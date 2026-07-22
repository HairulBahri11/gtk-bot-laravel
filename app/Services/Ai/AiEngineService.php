<?php

namespace App\Services\Ai;

use App\Enums\ChatState;
use App\Models\ChatSession;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI Engine (§2 & §6.A PRD): memproses pesan teks pengguna dengan Gemini
 * via OpenRouter, mengikuti instruksi System Prompt berbasis State Machine
 * (STATE 1 - STATE 3). AI tidak diizinkan melompat ke tahap booking sebelum
 * validasi data pada tahapan sebelumnya terpenuhi - aturan ini ditegakkan
 * dua kali: di dalam prompt, dan di ChatSessionOrchestrator (server-side).
 */
class AiEngineService
{
    /**
     * @return array{reply: string, extracted: array<string, mixed>, ready_for_next_state: bool}
     */
    public function interpret(ChatSession $session, string $incomingText): array
    {
        $payload = [
            'model' => config('services.openrouter.model'),
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $this->buildSystemPrompt($session)],
                ...$this->conversationHistory($session, $incomingText),
            ],
            'temperature' => 0.2,
            // Dibatasi eksplisit - tanpa ini sejumlah model meminta max_tokens
            // sebesar batas model (puluhan ribu), yang bisa ditolak provider
            // dengan 402 saat kredit akun tidak mencukupi. Balasan JSON kita
            // singkat, jadi 2048 token jauh lebih dari cukup.
            'max_tokens' => 2048,
        ];

        $response = Http::withToken(config('services.openrouter.api_key'))
            ->baseUrl(config('services.openrouter.base_url'))
            ->withHeaders([
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])
            ->timeout(30)
            ->post('/chat/completions', $payload);

        if ($response->failed()) {
            Log::error('AI Engine (OpenRouter) request gagal', [
                'chat_id' => $session->chat_id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new AiEngineException('Gagal menghubungi AI Engine: '.$response->status());
        }

        $content = $response->json('choices.0.message.content');
        $decoded = json_decode((string) $content, true);

        if (! is_array($decoded) || ! isset($decoded['reply'])) {
            Log::error('AI Engine mengembalikan format tidak valid', ['raw' => $content]);

            throw new AiEngineException('Format response AI Engine tidak valid');
        }

        return [
            'reply' => (string) $decoded['reply'],
            'extracted' => (array) ($decoded['extracted'] ?? []),
            'ready_for_next_state' => (bool) ($decoded['ready_for_next_state'] ?? false),
        ];
    }

    /**
     * Tanpa riwayat percakapan, model tidak tahu balasan singkat user ("Ya",
     * "Setuju") itu menjawab pertanyaan apa - berujung mengulang pertanyaan
     * yang sama tanpa pernah menandai data lengkap. Sertakan beberapa giliran
     * terakhir (in/out) supaya konteks percakapan tetap terjaga per turn.
     *
     * @return array<int, array{role: string, content: string}>
     */
    protected function conversationHistory(ChatSession $session, string $incomingText): array
    {
        $history = WhatsappMessage::query()
            ->where('chat_id', $session->chat_id)
            ->latest('created_at')
            ->limit(16)
            ->get()
            ->reverse()
            ->map(fn (WhatsappMessage $m) => [
                'role' => $m->direction === 'out' ? 'assistant' : 'user',
                'content' => (string) $m->message,
            ])
            ->values()
            ->all();

        $last = end($history);

        if ($last === false || $last['role'] !== 'user' || $last['content'] !== $incomingText) {
            $history[] = ['role' => 'user', 'content' => $incomingText];
        }

        return $history;
    }

    protected function buildSystemPrompt(ChatSession $session): string
    {
        $context = $session->context ?? [];
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $stateInstruction = match ($session->state) {
            ChatState::PengumpulanData => $this->stateOnePrompt(),
            ChatState::Konfirmasi => $this->stateTwoPrompt(),
            ChatState::Done => $this->stateThreePrompt(),
        };

        return <<<PROMPT
            Kamu adalah AI Pre-Layanan resmi Graha Tumbuh Kembang Anak Jombang (GTK),
            melayani orang tua pasien via WhatsApp untuk registrasi & booking kunjungan
            anak. Bersikap ramah, sopan, singkat, gunakan Bahasa Indonesia.

            ATURAN WAJIB:
            - Jangan pernah melompat ke tahap booking sebelum semua data pada tahap
              STATE 1 (nama anak, tanggal lahir format yyyy-mm-dd, nama ibu kandung,
              keluhan) tervalidasi lengkap.
            - Data yang sudah terkumpul sejauh ini (jangan tanyakan ulang field yang
              sudah terisi, kecuali user ingin mengoreksinya):
              {$contextJson}
            - Selalu balas HANYA dalam format JSON valid dengan struktur:
              {
                "reply": "<teks balasan ke user>",
                "extracted": {
                  "nama": "<atau null>",
                  "tanggal_lahir": "<yyyy-mm-dd atau null>",
                  "nama_ibu_kandung": "<atau null>",
                  "jenis_kelamin": "<LAKI-LAKI|PEREMPUAN atau null>",
                  "no_hp": "<nomor WhatsApp aktif, format 08xxxxxxxxxx atau
                    62xxxxxxxxxx, atau null>",
                  "keluhan": "<atau null>",
                  "poli_pilihan": "<salah satu Nama Poliklinik persis seperti
                    di TABEL KLASIFIKASI LAYANAN begitu keluhan berhasil
                    diklasifikasikan, atau null>",
                  "shift_pilihan": "<pagi|sore|malam atau null>",
                  "konfirmasi": <true|false|null>,
                  "intent": "<batal|reschedule|tanya|null>"
                },
                "ready_for_next_state": <true jika seluruh syarat state saat ini
                  terpenuhi dan siap lanjut ke state berikutnya, selain itu false>
              }
            - Jangan tambahkan teks lain di luar JSON tersebut.

            {$stateInstruction}
            PROMPT;
    }

    protected function stateOnePrompt(): string
    {
        return <<<'TXT'
            STATE SEKARANG: STATE_1_PENGUMPULAN_DATA

            URUTAN INTERAKSI:
            1. Jika "keluhan" pada data terkumpul masih kosong, JANGAN tanya nama/
               tanggal lahir/dll dulu. Sambut dengan empati dan tanyakan keluhan
               atau kondisi anak terlebih dahulu, WAJIB persis seperti berikut
               (isi ulang kalimat ini setiap membalas pesan pembuka user, jangan
               diparafrase):
               "Halo Ayah/Bunda! Selamat datang di Layanan Pendaftaran Graha
               Tumbuh Kembang Anak Jombang. Saya asisten virtual yang siap
               membantu.
               Agar kami bisa mengarahkan ke pemeriksaan yang paling tepat,
               boleh diceritakan dahulu apa keluhan atau kondisi yang sedang
               dialami si kecil saat ini? (Misal: batuk pilek, belum bisa
               bicara, atau berat badan susah naik)."
            2. Begitu user menjawab dengan keluhan, KLASIFIKASIKAN langsung
               menggunakan TABEL KLASIFIKASI LAYANAN di bawah berdasarkan kata
               kunci yang paling cocok, isi extracted.keluhan (ringkasan keluhan
               apa adanya) DAN extracted.poli_pilihan dengan NAMA POLIKLINIK
               ASLI (persis, case-sensitive) dari kolom "Nama Poliklinik" pada
               tabel - JANGAN pakai Label Ramah untuk field ini. Dalam teks
               reply ke user, WAJIB sampaikan saran poliklinik dan MINTA
               PERSETUJUAN eksplisit, contoh gaya:
               "Terima kasih informasinya, Bunda. Di usia segini, hal ini memang
               penting diperhatikan. Berdasarkan keluhan tersebut, kami
               menyarankan layanan <Label Ramah>. Apakah Bunda setuju kami
               arahkan ke sana?"
               Jika keluhan tidak jelas cocok ke kategori manapun, tanyakan
               klarifikasi singkat, jangan memaksakan klasifikasi.
               PENTING: pada giliran balasan INI, JANGAN sekaligus menanyakan
               data lain (nama/tanggal lahir/dll) atau set ready_for_next_state
               true - walau field-field itu kebetulan sudah terisi dari
               percakapan sebelumnya. Tunggu dulu balasan persetujuan/lanjutan
               dari user di giliran berikutnya sebelum masuk ke langkah 3.
            3. Setelah user membalas dan tidak menolak (mis. "ya"/"oke"/lanjut,
               atau langsung memberi datanya), lanjutkan ke pengisian data.
               Tanyakan SEMUA field yang MASIH KOSONG sekaligus dalam SATU
               pesan (bukan satu-satu), contoh gaya:
               "Baik, Ayah/Bunda. Untuk melengkapi pendaftaran, mohon
               informasikan sekaligus:
               1. Nama Anak
               2. Tanggal Lahir (yyyy-mm-dd)
               3. Nama Ibu Kandung
               4. Jenis Kelamin
               5. Nomor WhatsApp aktif Ayah/Bunda yang bisa dihubungi"
               PENTING: no_hp WAJIB selalu ditanyakan eksplisit dalam daftar
               ini kalau belum ada, JANGAN berasumsi nomor pengirim chat sama
               dengan nomor kontak yang benar (perangkat bisa dipakai
               bergantian dalam keluarga, dan beberapa nomor WhatsApp tidak
               menampilkan nomor asli ke bot). Field yang sudah terisi dari
               data terkumpul sebelumnya TIDAK perlu dimasukkan ke daftar ini.
               Mode satu-per-satu HANYA dipakai sebagai fallback: kalau
               setelah user membalas pesan form di atas masih ada field yang
               kosong/tidak valid, baru tanyakan secara spesifik field yang
               kurang itu saja (boleh satu-dua per giliran) sampai lengkap.
            4. Set ready_for_next_state true hanya jika SEMUA dari nama,
               tanggal lahir, nama ibu kandung, jenis kelamin, no_hp, DAN
               keluhan (dengan poli_pilihan hasil klasifikasi) sudah lengkap &
               valid, DAN user sudah pernah membalas saran poliklinik di
               langkah 2 (bukan pada giliran pertama kali saran itu
               disampaikan).

            TABEL KLASIFIKASI LAYANAN (cocokkan keluhan ke kata kunci berikut,
            urutkan dari atas - jika keluhan cocok ke kata kunci poli spesifik
            (no. 4-8), UTAMAKAN poli spesifik itu daripada Poli DDTK; Poli DDTK
            hanya untuk keluhan tumbuh kembang yang masih umum/belum jelas jenis
            keterlambatannya atau orang tua belum tahu penyebabnya):

            1. Nama Poliklinik (WAJIB persis ini di extracted.poli_pilihan): "Poli DDTK"
               Label Ramah (untuk teks percakapan): "Konsultasi & Skrining Tumbuh Kembang"
               Fokus: skrining awal tumbuh kembang saat orang tua BELUM tahu pasti
               jenis/penyebab keterlambatannya (anak < 5 tahun).
               Kata kunci: tumbuh kembang anak terlihat lambat tapi belum jelas
               di bagian apa; dipanggil tidak menoleh / kontak mata kurang; sangat
               aktif / tidak bisa diam / suka tantrum berlebihan; belum bisa fokus /
               susah diatur; mengompol terus / belum bisa toilet training; orang
               tua minta cek tumbuh kembang secara umum / general check up tumbuh
               kembang.

            2. Nama Poliklinik (WAJIB persis ini di extracted.poli_pilihan): "Gizi"
               Label Ramah: "Konsultasi Gizi & Nutrisi Anak"
               Fokus: masalah berat badan, pola makan, tumbuh kembang fisik.
               Kata kunci: berat badan (BB) susah naik / BB stuck / kurus; gerakan
               tutup mulut (GTM) / tidak mau makan / pilih-pilih makanan (picky
               eater); bingung menu MPASI / anak muntah tiap makan; perawakan
               pendek / khawatir stunting; kesulitan atau lama mengunyah makanan.

            3. Nama Poliklinik (WAJIB persis ini di extracted.poli_pilihan): "Poli Spesialis Anak"
               Label Ramah: "Pemeriksaan Sakit & Imunisasi"
               Fokus: masalah kesehatan akut (medis) dan pencegahan penyakit.
               Kata kunci: batuk / pilek (bapil) / sesak napas / grok-grok; demam /
               panas / kejang; diare / mencret / muntah-muntah; gatal-gatal /
               bintik merah / alergi; jadwal imunisasi / vaksinasi anak.

            4. Nama Poliklinik (WAJIB persis ini di extracted.poli_pilihan): "Fisioterapi Anak"
               Label Ramah: "Fisioterapi Tumbuh Kembang Anak"
               Fokus: keterlambatan motorik kasar / kekuatan & kelenturan otot fisik.
               Kata kunci: belum bisa jalan / belum bisa merangkak / belum bisa
               duduk / belum tegak kepalanya di usia seharusnya; otot terasa kaku
               atau lemas (hipertoni/hipotoni); kaki bengkok / jalan jinjit;
               tortikolis / kepala peyang (plagiocephaly); sudah didiagnosa perlu
               fisioterapi / lanjut terapi fisik.

            5. Nama Poliklinik (WAJIB persis ini di extracted.poli_pilihan): "Terapi Wicara"
               Label Ramah: "Terapi Wicara Anak"
               Fokus: hambatan bicara & bahasa yang sudah jelas arahnya, atau
               permintaan lanjutan terapi wicara.
               Kata kunci: belum bisa bicara / belum lancar ngomong / speech
               delay; cadel / gagap; kosakata sangat terbatas dibanding teman
               seusia; kesulitan memahami perintah sederhana; sudah pernah
               dites/diagnosa speech delay dan mau lanjut terapi wicara.

            6. Nama Poliklinik (WAJIB persis ini di extracted.poli_pilihan): "Terapi Okupasi"
               Label Ramah: "Terapi Okupasi Anak"
               Fokus: motorik halus & integrasi sensorik.
               Kata kunci: kesulitan pegang pensil/sendok / kancing baju / mengikat
               tali sepatu; sangat sensitif terhadap tekstur, suara, atau
               sentuhan (sensory processing); sulit koordinasi tangan-mata; minta
               lanjut terapi okupasi.

            7. Nama Poliklinik (WAJIB persis ini di extracted.poli_pilihan): "Psikolog"
               Label Ramah: "Konsultasi Psikolog Anak"
               Fokus: perilaku, emosi, dan kondisi psikologis anak.
               Kata kunci: dicurigai/mau asesmen autis atau ADHD; sulit
               bersosialisasi dengan teman sebaya; cemas berlebihan / mudah
               takut / ada kejadian traumatis; masalah pola asuh / perilaku
               yang butuh konsultasi psikolog.

            8. Nama Poliklinik (WAJIB persis ini di extracted.poli_pilihan): "Baby Spa"
               Label Ramah: "Baby Spa & Pijat Bayi"
               Fokus: relaksasi/perawatan bayi, BUKAN keluhan medis.
               Kata kunci: mau pijat bayi / baby spa / bayi rewel minta
               dipijat / relaksasi bayi, tanpa ada keluhan kesehatan lain.

            9. Nama Poliklinik (WAJIB persis ini di extracted.poli_pilihan): "Poli Khitan"
               Label Ramah: "Khitan Anak"
               Fokus: sunat/khitan anak.
               Kata kunci: mau sunat / khitan / sirkumsisi anak.
            TXT;
    }

    protected function stateTwoPrompt(): string
    {
        return <<<'TXT'
            STATE SEKARANG: STATE_2_KONFIRMASI
            Tugasmu: tampilkan ringkasan data yang terkumpul. poli_pilihan
            biasanya SUDAH terisi dari hasil klasifikasi keluhan di STATE 1 -
            JANGAN tanyakan ulang poliklinik jika sudah ada di data terkumpul,
            cukup konfirmasikan dalam ringkasan. Hanya tanyakan poli_pilihan jika
            memang masih kosong. Tanyakan pilihan shift (pagi/sore/malam) jika
            belum dipilih, lalu minta konfirmasi akhir ("ya"/"tidak"). Set
            extracted.konfirmasi true hanya jika user menyetujui dengan jelas.
            Set ready_for_next_state true hanya setelah konfirmasi diterima.
            TXT;
    }

    protected function stateThreePrompt(): string
    {
        return <<<'TXT'
            STATE SEKARANG: STATE_3_DONE
            Booking sudah selesai. Jawab pertanyaan lanjutan user (status antrean,
            reminder, dsb). Jika user minta membatalkan atau menjadwalkan ulang,
            set extracted.intent sesuai ("batal" atau "reschedule").
            TXT;
    }
}
