<?php

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Enums\ChatState;
use App\Enums\JenisLayanan;
use App\Enums\PatientMatchVerdict;
use App\Enums\Shift;
use App\Models\ChatSession;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Poliklinik;
use App\Models\QuotaShift;
use App\Models\WhatsappMessage;
use App\Services\Ai\AiEngineException;
use App\Services\Ai\AiEngineService;
use App\Services\Antrean\AntreanService;
use App\Services\Gtk\GtkApiException;
use App\Services\Gtk\GtkApiService;
use App\Services\Patient\PatientMatcher;
use App\Services\Quota\QuotaService;
use App\Services\Whatsapp\WhatsAppServiceInterface;
use App\Support\IndonesianDateReference;
use App\Support\IndonesianPhoneNumber;
use App\Support\NameSimilarity;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orkestrator utama state machine percakapan (§6.A PRD). Setiap pesan dari
 * nomor WhatsApp yang sama diproses sekuensial via WithoutOverlapping
 * (§6.C PRD) supaya tidak merusak state saat user mengirim pesan beruntun.
 */
class ProcessIncomingWhatsappMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(public string $chatId, public string $text) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // ->dontRelease() (default sebelumnya) TIDAK melepas job kembali ke
        // antrean kalau lock chat_id ini masih dipegang job lain - job itu
        // langsung DIHAPUS oleh CallQueuedHandler TANPA PERNAH menjalankan
        // handle() sama sekali (lihat Illuminate\Queue\Middleware\WithoutOverlapping
        // - kalau lock gagal didapat & releaseAfter null, tidak ada $next()
        // ATAU $job->release() yang dipanggil, jadi job dianggap selesai
        // begitu saja). Akibatnya: pesan user yang datang beruntun cepat bisa
        // SAMA SEKALI TIDAK PERNAH DIPROSES - tidak ada balasan AI, tidak ada
        // WhatsappMessage(direction=out), user cuma melihat balasan LAMA
        // seolah bot "mengambil dari memori"/tidak merespons pesan barunya.
        // ->releaseAfter() memastikan job SELALU dicoba lagi begitu lock
        // bebas, bukan hilang diam-diam.
        return [(new WithoutOverlapping($this->chatId))->expireAfter(120)->releaseAfter(5)];
    }

    public function handle(
        AiEngineService $ai,
        GtkApiService $gtk,
        AntreanService $antrean,
        QuotaService $quota,
        WhatsAppServiceInterface $wa,
        PatientMatcher $matcher,
    ): void {
        $session = ChatSession::firstOrCreate(
            ['chat_id' => $this->chatId],
            ['state' => ChatState::PengumpulanData->value, 'context' => []],
        );
        $session->last_message_at = now();

        // Tandai pesan sudah dibaca & tampilkan indikator mengetik sedini
        // mungkin (sebelum AI/GTK dipanggil) - selain terasa lebih manusiawi
        // buat user, ini juga langkah anti-blokir WA: nomor yang membalas
        // instan tanpa pernah "membaca"/"mengetik" lebih gampang dicurigai
        // sebagai bot murni oleh WhatsApp. reply() di bawah yang menghentikan
        // indikator ini begitu balasan benar-benar terkirim.
        $wa->sendSeen($this->chatId);
        $wa->startTyping($this->chatId);

        $this->autoFillPhoneFromChatId($session);

        // Pertanyaan jadwal dokter (mis. "dr. Retno hari ini jadwal jam
        // berapa?") dijawab LANGSUNG dari data doctor_schedules/quota_shifts
        // di sini - TIDAK PERNAH diserahkan ke AI. Kejadian nyata: walau
        // AiEngineService::buildSystemPrompt() sudah disuntik data jadwal
        // yang benar, model tetap kadang mengarahkan ke admin/mengaku "tidak
        // punya data" (pola halusinasi/kepatuhan instruksi yang tidak bisa
        // diandalkan) - jawaban seperti ini HARUS pasti benar, jadi jangan
        // pernah digantungkan ke LLM sama sekali. Sesi/state TIDAK disentuh
        // sama sekali di sini supaya alur booking yang sedang berjalan
        // (kalau ada) tidak terganggu - murni jawaban FAQ di luar state
        // machine.
        $jadwalReply = $this->tryAnswerDoctorScheduleQuestion($this->text);

        if ($jadwalReply !== null) {
            $session->save();
            $this->reply($wa, $jadwalReply);

            return;
        }

        try {
            $result = $ai->interpret($session, $this->text);
        } catch (AiEngineException $e) {
            $this->handleSystemError($wa, $session, 'AI Engine', $e->getMessage());

            return;
        }

        $this->resetIfDifferentPatient($session, $result['extracted']);
        $this->mergeContext($session, $result['extracted']);

        try {
            $reply = match ($session->state) {
                ChatState::PengumpulanData => $this->handleStateOne($session, $result, $gtk, $matcher, $antrean, $quota),
                ChatState::Konfirmasi => $this->handleStateTwo($session, $result, $antrean, $quota),
                ChatState::Done => $this->handleStateThree($session, $result, $antrean),
            };
        } catch (GtkApiException $e) {
            $this->handleSystemError($wa, $session, 'GTK API', $e->getMessage());

            return;
        }

        $session->save();
        $this->reply($wa, $reply);
    }

    /**
     * Isi otomatis no_hp dari chat_id sebelum AI diminta menginterpretasi
     * pesan, supaya AI tidak perlu (dan tidak boleh) menanyakan ulang nomor
     * WA kalau nomor pengirim sudah bisa dipastikan valid dan berformat
     * nomor Indonesia. Lihat IndonesianPhoneNumber untuk kenapa chat_id
     * berformat "...@lid" TIDAK BOLEH dianggap sebagai nomor telepon.
     */
    protected function autoFillPhoneFromChatId(ChatSession $session): void
    {
        $context = $session->context ?? [];

        if (filled($context['no_hp'] ?? null)) {
            return;
        }

        $phone = IndonesianPhoneNumber::fromChatId($this->chatId);

        if ($phone !== null) {
            $context['no_hp'] = $phone;
            $session->context = $context;
        }
    }

    /**
     * Kata kunci yang menandakan pesan user sedang menanyakan jam praktik
     * dokter (bukan sekadar menyebut kata "jadwal" dalam konteks lain,
     * mis. "jadwalkan saya" saat konfirmasi booking - kombinasi dengan nama
     * dokter di bawah ini yang membuat deteksi cukup spesifik).
     */
    protected const JADWAL_KEYWORDS = ['jadwal', 'jam berapa', 'jam praktik', 'jam praktek', 'praktik jam', 'buka jam', 'praktek jam'];

    /**
     * Gelar/singkatan umum yang dibuang dari nama dokter sebelum dicocokkan
     * ke teks user - tanpa ini token seperti "dr"/"sp"/"a" akan cocok ke
     * hampir semua pesan (false positive masif).
     */
    protected const GELAR_STOPWORDS = ['dr', 'drg', 'sp', 'kj', 'ra', 'a', 'm', 'ked'];

    /**
     * Jawab pertanyaan jadwal dokter LANGSUNG dari data lokal (doctor_schedules
     * + status quota_shifts), tanpa melibatkan AI sama sekali - lihat catatan
     * di handle(). HANYA mencakup dokter dengan jadwal manual (source='manual',
     * persis seperti /pre-layanan/jadwal) - dokter yang hanya punya jadwal
     * hasil sync GTK tetap diproses normal lewat AI (di luar cakupan fitur ini).
     *
     * Dua bentuk jawaban: (1) nama dokter disebutkan -> jadwal dokter itu
     * saja, (2) tidak ada nama dokter tapi pesan jelas menanyakan jadwal
     * DOKTER (bukan mis. "jadwal kunjungan saya") -> daftar semua dokter
     * pada tanggal yang dimaksud. Tanggal diekstrak dari teks (hari ini/
     * besok/nama hari/tanggal eksplisit) - default ke [hari ini, besok]
     * kalau tidak ada referensi tanggal sama sekali.
     *
     * @return string|null null kalau pesan bukan pertanyaan jadwal dokter.
     */
    protected function tryAnswerDoctorScheduleQuestion(string $text): ?string
    {
        $normalized = Str::lower($text);

        $looksLikeScheduleQuestion = collect(self::JADWAL_KEYWORDS)->contains(fn (string $kw) => Str::contains($normalized, $kw));

        if (! $looksLikeScheduleQuestion) {
            return null;
        }

        $targetDates = $this->resolveTargetDates($normalized);

        $manualDoctors = Doctor::query()
            ->where('is_active', true)
            ->whereHas('schedules', fn ($q) => $q->where('source', 'manual'))
            ->get();

        $matched = $manualDoctors->first(function (Doctor $doctor) use ($normalized) {
            $nameTokens = collect(preg_split('/[\s.,]+/', $doctor->nama_dokter))
                ->map(fn (string $t) => Str::lower($t))
                ->reject(fn (string $t) => $t === '' || in_array($t, self::GELAR_STOPWORDS, true))
                ->filter(fn (string $t) => mb_strlen($t) >= 3);

            return $nameTokens->contains(fn (string $token) => Str::contains($normalized, $token));
        });

        if ($matched) {
            return $this->buildDoctorScheduleAnswer($matched, $targetDates);
        }

        if (Str::contains($normalized, 'dokter')) {
            return $this->buildAllDoctorsScheduleAnswer($targetDates);
        }

        return null;
    }

    /**
     * @return array<int, string> tanggal target (yyyy-mm-dd) - satu elemen
     *                            kalau teks menyebut tanggal spesifik,
     *                            default [hari ini, besok] kalau tidak ada
     *                            referensi tanggal sama sekali di teks.
     */
    protected function resolveTargetDates(string $normalizedText): array
    {
        $explicit = IndonesianDateReference::extract($normalizedText);

        return $explicit !== null
            ? [$explicit]
            : [Carbon::today()->toDateString(), Carbon::tomorrow()->toDateString()];
    }

    /**
     * "hari ini"/"besok" untuk tanggal yang benar-benar hari ini/besok,
     * selain itu nama hari + tanggal lengkap - supaya jawaban tetap jelas
     * walau user menanyakan hari yang lebih jauh (mis. "hari Jumat").
     */
    protected function relativeDayLabel(Carbon $date): string
    {
        if ($date->isToday()) {
            return 'hari ini';
        }

        if ($date->isTomorrow()) {
            return 'besok';
        }

        $hariIndo = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

        return ($hariIndo[$date->dayOfWeekIso] ?? '').', '.$date->translatedFormat('d F Y');
    }

    /**
     * Susun jawaban jadwal untuk SATU dokter (manual saja) pada tanggal-
     * tanggal target, dilapis status harian dari quota_shifts (cancel/delay).
     *
     * @param  array<int, string>  $targetDates
     */
    protected function buildDoctorScheduleAnswer(Doctor $doctor, array $targetDates): string
    {
        $shiftLabel = ['pagi' => 'Pagi', 'sore' => 'Sore', 'malam' => 'Malam'];
        $lines = [];

        foreach ($targetDates as $tanggal) {
            $date = Carbon::parse($tanggal);
            $hari = self::HARI_BY_ISO[$date->dayOfWeekIso] ?? null;

            if (! $hari) {
                continue;
            }

            $schedules = DoctorSchedule::query()
                ->with('poliklinik')
                ->where('kode_dokter', $doctor->kode_dokter)
                ->where('hari', $hari)
                ->where('source', 'manual')
                ->orderBy('jam_mulai')
                ->get();

            if ($schedules->isEmpty()) {
                continue;
            }

            $statuses = QuotaShift::query()
                ->where('kode_dokter', $doctor->kode_dokter)
                ->whereDate('tanggal', $tanggal)
                ->get()
                ->keyBy(fn (QuotaShift $q) => $q->shift->value);

            $dayLabel = $this->relativeDayLabel($date);

            foreach ($schedules as $schedule) {
                $status = $statuses[$schedule->shift->value] ?? null;
                $jam = substr($schedule->jam_mulai, 0, 5).'-'.substr($schedule->jam_selesai, 0, 5);
                $shiftText = $shiftLabel[$schedule->shift->value] ?? $schedule->shift->value;

                $statusText = match ($status?->status) {
                    'cancelled' => ' (DIBATALKAN'.($status->reason ? ": {$status->reason}" : '').')',
                    'delayed' => ' (delay '.$status->delay_minutes.' menit'.($status->reason ? ": {$status->reason}" : '').')',
                    default => '',
                };

                $lines[] = "{$dayLabel} shift {$shiftText} pukul {$jam}{$statusText}, di {$schedule->poliklinik?->nama_poliklinik}";
            }
        }

        if (empty($lines)) {
            $periode = count($targetDates) > 1 ? 'hari ini maupun besok' : 'tanggal yang ditanyakan';

            return "Mohon maaf, {$doctor->nama_dokter} tidak memiliki jadwal praktik untuk {$periode}. "
                .'Silakan tanyakan tanggal lain atau hubungi kami untuk info lebih lanjut.';
        }

        return "Jadwal praktik {$doctor->nama_dokter}: ".implode('; ', $lines).'.';
    }

    /**
     * Susun jawaban daftar SEMUA dokter (manual saja) pada tanggal-tanggal
     * target - dipakai saat pesan menanyakan jadwal dokter tanpa menyebut
     * nama dokter tertentu (mis. "jadwal dokter besok").
     *
     * @param  array<int, string>  $targetDates
     */
    protected function buildAllDoctorsScheduleAnswer(array $targetDates): string
    {
        $shiftLabel = ['pagi' => 'Pagi', 'sore' => 'Sore', 'malam' => 'Malam'];
        $sections = [];

        foreach ($targetDates as $tanggal) {
            $date = Carbon::parse($tanggal);
            $hari = self::HARI_BY_ISO[$date->dayOfWeekIso] ?? null;

            if (! $hari) {
                continue;
            }

            $schedules = DoctorSchedule::query()
                ->with(['doctor', 'poliklinik'])
                ->where('hari', $hari)
                ->where('source', 'manual')
                ->whereHas('doctor', fn ($q) => $q->where('is_active', true))
                ->orderBy('jam_mulai')
                ->get();

            if ($schedules->isEmpty()) {
                continue;
            }

            $statuses = QuotaShift::query()
                ->whereDate('tanggal', $tanggal)
                ->get()
                ->keyBy(fn (QuotaShift $q) => $q->kode_dokter.'|'.$q->shift->value);

            // Kelompokkan per dokter supaya satu dokter dengan beberapa shift
            // di hari yang sama tampil sebagai satu baris ringkas, bukan
            // diulang per shift.
            $doctorLines = $schedules->groupBy('kode_dokter')->map(function ($doctorSchedules) use ($statuses, $shiftLabel) {
                $first = $doctorSchedules->first();
                $namaDokter = $first->doctor?->nama_dokter ?? $first->kode_dokter;
                $namaPoli = $first->poliklinik?->nama_poliklinik;

                $shiftParts = $doctorSchedules->map(function (DoctorSchedule $s) use ($statuses, $shiftLabel) {
                    $status = $statuses[$s->kode_dokter.'|'.$s->shift->value] ?? null;
                    $jam = substr($s->jam_mulai, 0, 5).'-'.substr($s->jam_selesai, 0, 5);
                    $shiftText = $shiftLabel[$s->shift->value] ?? $s->shift->value;

                    $statusText = match ($status?->status) {
                        'cancelled' => ' (DIBATALKAN)',
                        'delayed' => ' (delay '.$status->delay_minutes.' menit)',
                        default => '',
                    };

                    return "{$shiftText} {$jam}{$statusText}";
                })->implode(', ');

                return "- {$namaDokter} ({$namaPoli}): {$shiftParts}";
            })->values();

            $dayLabel = $this->relativeDayLabel($date);
            $sections[] = "Jadwal dokter {$dayLabel} ({$date->translatedFormat('d F Y')}):\n".$doctorLines->implode("\n");
        }

        if (empty($sections)) {
            return 'Mohon maaf, tidak ada jadwal dokter tercatat untuk tanggal yang ditanyakan. Silakan hubungi kami untuk info lebih lanjut.';
        }

        return implode("\n\n", $sections);
    }

    /**
     * Titik tangkap tunggal untuk kegagalan sistem (AI Engine maupun GTK
     * API) - user tetap dapat balasan yang mengarahkan ke kontak admin,
     * dan admin otomatis diberi tahu detail errornya lewat WhatsApp supaya
     * bisa ditindaklanjuti tanpa harus memantau log server.
     */
    protected function handleSystemError(WhatsAppServiceInterface $wa, ChatSession $session, string $source, string $errorDetail): void
    {
        Log::error("Sistem gagal memproses pesan [{$source}]", [
            'chat_id' => $this->chatId,
            'error' => $errorDetail,
        ]);

        $session->save();

        $adminNumber = config('services.admin.whatsapp_number');

        if ($adminNumber) {
            $wa->sendText("{$adminNumber}@c.us", "Bot GTK mengalami gangguan.\n"
                ."Sumber: {$source}\n"
                ."Chat ID: {$this->chatId}\n"
                ."Pesan user: {$this->text}\n"
                ."Detail error: {$errorDetail}");
        }

        $adminContact = $adminNumber ? "wa.me/{$adminNumber}" : 'admin kami';

        $this->reply($wa, 'Mohon maaf, sistem kami sedang mengalami kendala teknis saat memproses permintaan Anda. '
            ."Tim kami sudah otomatis diberi tahu. Jika perlu bantuan segera, silakan hubungi {$adminContact}.");
    }

    /**
     * Deteksi pergantian pasien pada nomor WA yang sama (mis. daftarin anak
     * lain setelah sesi sebelumnya macet sebelum STATE_3_DONE). no_rm hanya
     * diisi lewat resolvePatient() di STATE_1, jadi begitu nama+tanggal_lahir
     * baru terdeteksi berbeda dari yang tersimpan, sesi WAJIB direset total
     * supaya booking berikutnya tidak nyasar ke identitas pasien lama.
     *
     * Perbandingan nama sengaja memakai kemiripan fuzzy (NameSimilarity),
     * BUKAN exact match lagi - kalau masih exact match, koreksi ejaan kecil
     * dari orang tua sendiri (mis. baru sadar salah ketik "Budy" jadi
     * "Budi") akan salah terdeteksi sebagai "pasien lain" dan menghapus
     * seluruh progres sesi yang sudah terkumpul. Ambang batasnya SENGAJA
     * memakai nama_confident_threshold yang sama dengan PatientMatcher
     * (bukan angka terpisah) - supaya "pasien yang sama untuk keperluan
     * pencarian" dan "pasien yang sama untuk keperluan kontinuitas sesi"
     * tidak bisa diam-diam berbeda definisi. tanggal_lahir TETAP exact
     * match (tidak ikut fuzzy) - orang tua jarang salah ketik tanggal lahir
     * anak sendiri sesering salah ketik ejaan nama, jadi tanggal lahir yang
     * benar-benar berbeda tetap sinyal kuat bahwa ini memang pasien lain.
     */
    protected function resetIfDifferentPatient(ChatSession $session, array $extracted): void
    {
        if (! $session->no_rm) {
            return;
        }

        $newNama = $extracted['nama'] ?? null;
        $newTanggalLahir = $extracted['tanggal_lahir'] ?? null;

        if (! $newNama || ! $newTanggalLahir) {
            return;
        }

        $context = $session->context ?? [];
        $sameNama = NameSimilarity::score($newNama, $context['nama'] ?? null) >= config('gtk.patient_matching.nama_confident_threshold');
        $sameTanggalLahir = $newTanggalLahir === ($context['tanggal_lahir'] ?? null);

        if ($sameNama && $sameTanggalLahir) {
            return;
        }

        Log::info('Sesi direset - terdeteksi pasien berbeda pada nomor WA yang sama', [
            'chat_id' => $this->chatId,
            'pasien_lama' => $context['nama'] ?? null,
            'no_rm_lama' => $session->no_rm,
            'pasien_baru' => $newNama,
        ]);

        $session->state = ChatState::PengumpulanData->value;
        $session->context = [];
        $session->no_rm = null;
        $session->step = null;
    }

    /**
     * Flag boolean yang sekali true harus tetap true - jangan biarkan giliran
     * berikutnya membalikkannya ke false/null (lihat catatan di
     * handleStateOne() untuk masing-masing flag).
     */
    protected const STICKY_TRUE_KEYS = ['poli_disetujui', 'no_hp_dikonfirmasi', 'tanggal_kunjungan_dijawab', 'jenis_layanan_dijawab'];

    /**
     * Field context yang benar-benar dibaca kode kita - HARUS sinkron persis
     * dengan struktur "extracted" di AiEngineService::buildSystemPrompt().
     * "konfirmasi" & "intent" sengaja tidak masuk sini karena keduanya
     * per-giliran (dibaca langsung dari $result, tidak pernah disimpan ke
     * context). Whitelist ini WAJIB ada - pernah kejadian nyata model
     * mengembalikan nama field yang sedikit meleset dari skema (mis.
     * "tanggal_kunjungan_terkonfirmasi" alih-alih "tanggal_kunjungan"),
     * dan tanpa whitelist ini field siluman itu diam-diam ikut tersimpan ke
     * context (memenuhi kolom dengan sampah) SEMENTARA field yang benar-benar
     * dibaca kode (mis. tanggal_kunjungan) tetap kosong - state machine jadi
     * salah baca data tanpa ada tanda error apapun.
     */
    protected const KNOWN_CONTEXT_KEYS = [
        'nama', 'tanggal_lahir', 'tempat_lahir', 'nama_ibu_kandung', 'jenis_kelamin', 'no_hp',
        'no_hp_dikonfirmasi', 'keluhan', 'poli_pilihan', 'poli_disetujui',
        'jenis_layanan', 'jenis_layanan_dijawab',
        'shift_pilihan', 'tanggal_kunjungan', 'tanggal_kunjungan_dijawab',
    ];

    protected function mergeContext(ChatSession $session, array $extracted): void
    {
        $context = $session->context ?? [];

        foreach ($extracted as $key => $value) {
            if (! in_array($key, self::KNOWN_CONTEXT_KEYS, true)) {
                continue;
            }

            if ($value !== null && $value !== '') {
                if (in_array($key, self::STICKY_TRUE_KEYS, true) && ($context[$key] ?? false) === true) {
                    continue;
                }

                $context[$key] = $value;
            }
        }

        $session->context = $context;
    }

    /**
     * Marker literal yang AI boleh sisipkan di tengah "reply" untuk menandai
     * batas antar pesan WhatsApp terpisah (lihat instruksi di
     * AiEngineService::buildSystemPrompt()/stateOnePrompt() - dipakai mis.
     * untuk memisahkan kalimat empati+arahan poliklinik dari daftar isian
     * data pendaftaran, supaya terkirim sebagai dua pesan berurutan seperti
     * orang mengetik, bukan satu blok teks panjang). Kalau marker ini tidak
     * ada sama sekali (kasus umum), hasilnya tetap satu pesan seperti biasa.
     */
    protected const MESSAGE_SPLIT_MARKER = '|||PESAN_BARU|||';

    protected function reply(WhatsAppServiceInterface $wa, string $message): void
    {
        $parts = collect(explode(self::MESSAGE_SPLIT_MARKER, $message))
            ->map(fn (string $part) => trim($part))
            ->filter(fn (string $part) => $part !== '')
            ->values();

        if ($parts->isEmpty()) {
            $parts = collect([trim($message)]);
        }

        $lastIndex = $parts->count() - 1;

        foreach ($parts as $index => $part) {
            $wa->sendText($this->chatId, $part);

            WhatsappMessage::create([
                'chat_id' => $this->chatId,
                'direction' => 'out',
                'message' => $part,
            ]);

            if ($index === $lastIndex) {
                $wa->stopTyping($this->chatId);
            } else {
                // Bukan pesan terakhir - tampilkan lagi indikator mengetik
                // sebelum pesan berikutnya dikirim, supaya beberapa pesan
                // berurutan ini tetap terasa seperti diketik satu-satu,
                // bukan diam-diam langsung nyerocos semuanya sekaligus.
                $wa->startTyping($this->chatId);
            }
        }
    }

    /**
     * STATE_1_PENGUMPULAN_DATA - §6.A PRD: tidak melompat ke booking sebelum
     * nama, tanggal lahir, nama ibu, jenis kelamin, dan keluhan lengkap.
     */
    protected function handleStateOne(ChatSession $session, array $result, GtkApiService $gtk, PatientMatcher $matcher, AntreanService $antrean, QuotaService $quota): string
    {
        $context = $session->context;

        // no_hp bisa juga terisi dari jawaban bebas user (bukan cuma auto-fill
        // dari chat_id yang sudah pasti valid) - validasi ulang di sini sebagai
        // nomor seluler Indonesia yang sah. Kalau tidak valid, kosongkan lagi
        // supaya field ini dianggap belum terisi & AI menanyakannya ulang.
        if (filled($context['no_hp'] ?? null)) {
            $normalizedNoHp = IndonesianPhoneNumber::normalize((string) $context['no_hp']);

            if ($normalizedNoHp === null) {
                // Nomor tidak valid - kosongkan lagi keduanya supaya field ini
                // dianggap belum terisi & AI menanyakan (dan meminta konfirmasi)
                // ulang, bukan lolos dengan nomor yang salah format.
                unset($context['no_hp'], $context['no_hp_dikonfirmasi']);
            } else {
                $context['no_hp'] = $normalizedNoHp;
            }

            $session->context = $context;
        }

        // jenis_layanan WAJIB salah satu nilai valid JenisLayanan (bukan
        // diturunkan/ditebak dari poliklinik atau keluhan - lihat docblock
        // enum JenisLayanan) - kalau AI menandai sudah dijawab tapi nilainya
        // tidak valid/tidak dikenal, kosongkan lagi keduanya supaya field
        // ini dianggap belum terjawab & ditanyakan ulang, persis pola
        // validasi no_hp di atas.
        if (($context['jenis_layanan_dijawab'] ?? false) === true
            && JenisLayanan::tryFrom((string) ($context['jenis_layanan'] ?? '')) === null) {
            unset($context['jenis_layanan'], $context['jenis_layanan_dijawab']);
            $session->context = $context;
        }

        $required = ['nama', 'tanggal_lahir', 'tempat_lahir', 'nama_ibu_kandung', 'jenis_kelamin', 'no_hp', 'keluhan'];
        $complete = collect($required)->every(fn ($field) => filled($context[$field] ?? null));

        // Jangan andalkan ready_for_next_state semata untuk syarat persetujuan
        // poliklinik - tegakkan juga di sisi server memakai flag poli_disetujui
        // yang tersimpan permanen di context (lihat AiEngineService::stateOnePrompt),
        // supaya model yang lupa/keliru menandai giliran pertama sebagai "sudah
        // setuju" tidak bisa melompati konfirmasi ini.
        $poliDisetujui = blank($context['poli_pilihan'] ?? null) || ($context['poli_disetujui'] ?? false) === true;

        // no_hp sering terisi otomatis dari nomor pengirim chat, bukan
        // diketik sendiri oleh orang tua - supaya nomor yang tersimpan di
        // data pasien tidak salah, tegakkan juga di server bahwa nomor itu
        // WAJIB sudah dikonfirmasi (lihat "PENTING soal no_hp" di
        // AiEngineService::stateOnePrompt) sebelum lanjut ke booking.
        $noHpDikonfirmasi = ($context['no_hp_dikonfirmasi'] ?? false) === true;

        // jenis_layanan (Pemeriksaan, Konsultasi Gizi, atau Konsultasi
        // Tumbuh Kembang) WAJIB ditanyakan & dijawab eksplisit sebelum
        // booking - ketiga pool kuota ini terisolasi (lihat QuotaShift::
        // tersisaFor()), jadi AI TIDAK BOLEH menyimpulkannya sendiri dari
        // keluhan/poliklinik.
        $jenisLayananDijawab = ($context['jenis_layanan_dijawab'] ?? false) === true;

        // shift_pilihan & tanggal_kunjungan (jadwal kunjungan) kini
        // dikumpulkan bersama formulir STATE_1, bukan lagi ditanyakan
        // belakangan di STATE_2 (lihat AiEngineService::stateOnePrompt()
        // "PENTING soal jadwal kunjungan") - gate server-side yang sama
        // persis seperti field lain di atas, supaya model yang lupa/keliru
        // tidak bisa melompati pengisian jadwal kunjungan sebelum
        // resolvePatient() dijalankan.
        $shiftPilihanTerisi = filled($context['shift_pilihan'] ?? null);

        // tanggal_kunjungan_dijawab bersifat STICKY_TRUE (lihat mergeContext())
        // - kalau AI pernah keliru menandainya true TANPA pernah mengisi
        // tanggal_kunjungan itu sendiri di giliran yang sama (kejadian nyata,
        // lihat validasi serupa di resolveSlotAndReply()), flag itu akan
        // macet permanen true & AI tidak akan pernah menanyakannya ulang
        // (instruksi prompt: "SEKALI tanggal_kunjungan_dijawab true, jangan
        // tanyakan ulang"). Bersihkan (unset) di sini SEBELUM dievaluasi
        // sebagai gate, sama seperti pola reset no_hp/jenis_layanan tidak
        // valid di atas, supaya AI wajib menanyakan ulang secara eksplisit.
        if (($context['tanggal_kunjungan_dijawab'] ?? false) === true && blank($context['tanggal_kunjungan'] ?? null)) {
            unset($context['tanggal_kunjungan_dijawab']);
            $session->context = $context;
        }

        $tanggalKunjunganDijawab = ($context['tanggal_kunjungan_dijawab'] ?? false) === true
            && filled($context['tanggal_kunjungan'] ?? null);

        if (! $complete || ! $poliDisetujui || ! $noHpDikonfirmasi || ! $jenisLayananDijawab
            || ! $shiftPilihanTerisi || ! $tanggalKunjunganDijawab || ! $result['ready_for_next_state']) {
            return $result['reply'];
        }

        // resolvePatient() dijalankan ULANG dari nol setiap giliran selama
        // masih di STATE_1 (bukan cuma sekali) - sengaja dibuat begini
        // (pola sama persis dengan slot_ditawarkan di handleStateTwo())
        // supaya kalau user mengoreksi ejaan nama/tanggal lahir SETELAH
        // ditanya konfirmasi (lihat awaiting_confirmation di bawah), koreksi
        // itu otomatis dievaluasi ulang tanpa butuh mekanisme deteksi
        // khusus - context['nama']/['tanggal_lahir'] sudah ter-update oleh
        // mergeContext() sebelum method ini dipanggil.
        $resolution = $this->resolvePatient($session, $gtk, $matcher, $context, $result);

        if ($resolution['awaiting_confirmation']) {
            return $resolution['reply'];
        }

        $noRm = $resolution['no_rm'];
        $session->no_rm = $noRm;
        $session->state = ChatState::Konfirmasi->value;
        $session->step = 'pilih_poli_shift';

        // poli_pilihan, jenis_layanan, shift_pilihan, DAN tanggal_kunjungan
        // sudah WAJIB semuanya terisi di titik ini (semuanya syarat
        // ready_for_next_state di atas - lihat AiEngineService::
        // stateOnePrompt() "Jadwal kunjungan" kini dikumpulkan bersama
        // formulir STATE_1, bukan ditanyakan belakangan) - jadi langsung
        // lanjut ke resolusi slot & tawarkan jadwal SATU KALI, sama persis
        // dengan STATE_2 (lihat resolveSlotAndReply()), tanpa perlu
        // menanyakan shift/tanggal lagi di sini.
        return $this->resolveSlotAndReply($session, $context, $result, $antrean, $quota);
    }

    /**
     * Resolusi identitas pasien BERLAPIS - dulu satu-satunya sinyal adalah
     * exact match "nama"+"tanggal_lahir" ke GTK, kandidat pertama dari
     * "list" langsung dipakai/dibuang tanpa penilaian apapun. Akibat nyata:
     * typo/variasi ejaan kecil pada nama (mis. "Budy" vs "Budi" di data)
     * membuat pasien lama dianggap "tidak ditemukan" lalu DIAM-DIAM
     * didaftarkan ulang sebagai rekam medis baru (duplikat), padahal
     * datanya sebenarnya sudah ada.
     *
     * Sekarang SEMUA kandidat yang dikembalikan GTK dinilai lewat
     * PatientMatcher (bukan cuma list[0]), dan kalau GTK sendiri belum
     * cukup yakin, cache lokal `patients` (indexed nama+tanggal_lahir,
     * sebelumnya tidak pernah dipakai untuk pencarian sama sekali) ikut
     * dikonsultasikan - berguna untuk pasien yang pernah booking lewat bot
     * ini sebelumnya walau pencarian nama di sisi GTK sendiri gagal karena
     * typo. Cara memanggil GTK (`cariPasien` dengan nama+tanggal_lahir)
     * SENGAJA tidak diubah/diperlonggar - perilaku pencarian di sisi GTK
     * sendiri di luar kendali & belum terverifikasi menerima parameter
     * lain, jadi cukup nilai ulang apa yang sudah dikembalikan.
     *
     * Tiga kemungkinan hasil dari PatientMatcher (lihat docblock kelas itu
     * untuk detail skor/ambang batas):
     * - Confident: langsung pakai, PERSIS seperti perilaku lama - tidak ada
     *   friksi tambahan untuk kasus umum "nama diketik benar".
     * - Probable: JANGAN langsung pakai ATAU langsung anggap pasien baru -
     *   tawarkan kandidatnya & minta konfirmasi eksplisit dulu (pola sama
     *   persis dengan slot_ditawarkan di handleStateTwo()), supaya tidak
     *   diam-diam salah nyambung ke rekam medis orang lain MAUPUN diam-diam
     *   membuat duplikat padahal pasiennya sudah ada.
     * - NoMatch: buat pasien baru, PERSIS seperti perilaku lama.
     *
     * @return array{awaiting_confirmation: bool, reply: ?string, no_rm: ?string}
     */
    protected function resolvePatient(ChatSession $session, GtkApiService $gtk, PatientMatcher $matcher, array $context, array $result): array
    {
        // no_hp normalnya sudah terisi lewat autoFillPhoneFromChatId() atau
        // hasil tanya-jawab AI. Tetap divalidasi/normalisasi ulang di sini
        // sebagai jaring pengaman terakhir - JANGAN PERNAH pakai potongan
        // chat_id mentah, karena kontak berformat "...@lid" tidak mengekspos
        // nomor asli sama sekali (lihat IndonesianPhoneNumber).
        $noHp = IndonesianPhoneNumber::normalize($context['no_hp'] ?? null)
            ?? IndonesianPhoneNumber::fromChatId($session->chat_id)
            ?? '-';

        $submitted = [
            'nama' => $context['nama'],
            'tanggal_lahir' => $context['tanggal_lahir'],
            'nama_ibu_kandung' => $context['nama_ibu_kandung'] ?? null,
            'no_hp' => $noHp !== '-' ? $noHp : null,
        ];

        try {
            $found = $gtk->cariPasien([
                'nama' => $context['nama'],
                'tanggal_lahir' => $context['tanggal_lahir'],
            ]);
            $gtkList = $found['list'] ?? [];
        } catch (GtkApiException $e) {
            $gtkList = [];
        }

        $gtkCandidates = $this->mapGtkCandidates($gtkList);
        $evaluation = $matcher->evaluate($submitted, $gtkCandidates);

        // Cache lokal HANYA dikonsultasikan kalau kandidat dari GTK sendiri
        // belum cukup meyakinkan (Confident) - lihat keputusan produk di
        // atas: jangan pernah mengubah cara memanggil API GTK, cache lokal
        // murni lapisan tambahan yang sepenuhnya kita kendalikan sendiri.
        if ($evaluation['verdict'] !== PatientMatchVerdict::Confident) {
            $localPatients = Patient::query()->where('tanggal_lahir', $context['tanggal_lahir'])->get();

            if ($localPatients->isNotEmpty()) {
                $localCandidates = $this->mapLocalCandidates($localPatients);
                // Kandidat GTK didahulukan (index lebih kecil) saat dedup
                // supaya kalau pasien yang sama muncul di kedua sumber,
                // field-fieldnya memakai data GTK yang lebih baru - cache
                // lokal cuma snapshot lama dari kunjungan/booking sebelumnya.
                $merged = $this->dedupeCandidatesByNoRm([...$gtkCandidates, ...$localCandidates]);
                $evaluation = $matcher->evaluate($submitted, $merged);
            }
        }

        if ($evaluation['verdict'] === PatientMatchVerdict::Probable) {
            return $this->handleProbableMatch($session, $context, $result, $evaluation['candidate'], $noHp);
        }

        // Confident atau NoMatch: bersihkan sisa penawaran pasien dari
        // giliran sebelumnya kalau ada (mis. giliran lalu Probable, lalu
        // user mengoreksi datanya sedemikian rupa sehingga giliran ini
        // sudah Confident/tidak match sama sekali) - supaya context tidak
        // menyimpan pasien_ditawarkan basi yang sudah tidak relevan.
        if (($context['pasien_ditawarkan'] ?? null) !== null) {
            unset($context['pasien_ditawarkan']);
            $session->context = $context;
        }

        return $evaluation['verdict'] === PatientMatchVerdict::Confident
            ? $this->commitMatchedPatient($evaluation['candidate'], $context, $noHp)
            : $this->createNewPatient($gtk, $context, $noHp);
    }

    /**
     * Kandidat "Probable" (lihat resolvePatient()) tidak langsung dipakai
     * ATAU langsung dianggap pasien baru - tawarkan dulu & tunggu
     * konfirmasi eksplisit, mengikuti pola slot_ditawarkan di
     * handleStateTwo() persis: giliran PERTAMA kandidat ini ditawarkan,
     * kirim pertanyaan konfirmasi yang DISUSUN SERVER (bukan diserahkan ke
     * AI - salah tafsir/halusinasi AI di sini berisiko tinggi, ini soal
     * identitas rekam medis, bukan sekadar jadwal). Baru pada giliran
     * BERIKUTNYA kalau kandidat yang SAMA masih ditawarkan (belum berubah
     * karena user mengoreksi data) DAN AI menandai extracted.konfirmasi
     * true (user menjawab "ya"), kandidat ini benar-benar dipakai.
     *
     * @return array{awaiting_confirmation: bool, reply: ?string, no_rm: ?string}
     */
    protected function handleProbableMatch(ChatSession $session, array $context, array $result, array $candidate, string $noHp): array
    {
        $offeredNoRm = $candidate['no_rm'];

        if (($context['pasien_ditawarkan'] ?? null) !== $offeredNoRm) {
            $context['pasien_ditawarkan'] = $offeredNoRm;
            $session->context = $context;

            return [
                'awaiting_confirmation' => true,
                'reply' => $this->buildPatientConfirmationQuestion($candidate),
                'no_rm' => null,
            ];
        }

        // ready_for_next_state tidak perlu dicek ulang di sini - handleStateOne()
        // sudah menegakkannya sebelum resolvePatient() (dan method ini)
        // pernah dipanggil sama sekali pada giliran ini.
        $confirmed = ($result['extracted']['konfirmasi'] ?? false) === true;

        if (! $confirmed) {
            return ['awaiting_confirmation' => true, 'reply' => $result['reply'], 'no_rm' => null];
        }

        unset($context['pasien_ditawarkan']);
        $session->context = $context;

        return $this->commitMatchedPatient($candidate, $context, $noHp);
    }

    protected function buildPatientConfirmationQuestion(array $candidate): string
    {
        $tanggalLabel = $candidate['tanggal_lahir'] !== null
            ? Carbon::parse($candidate['tanggal_lahir'])->translatedFormat('d F Y')
            : 'tidak diketahui';

        return "Mohon konfirmasi, apakah data pasien yang dimaksud adalah *{$candidate['nama']}* "
            ."(lahir {$tanggalLabel})? Kami menemukan kecocokan berdasarkan tanggal lahir yang sama. "
            .'Balas "Ya" jika benar, atau beri tahu kami nama lengkap dan tanggal lahir yang benar kalau belum sesuai.';
    }

    /**
     * @return array{awaiting_confirmation: bool, reply: ?string, no_rm: ?string}
     */
    protected function commitMatchedPatient(array $candidate, array $context, string $noHp): array
    {
        // Kandidat sumber cache lokal: sudah berupa model Patient utuh,
        // cukup di-refresh (bukan re-upsert dari nol seperti sumber GTK) -
        // nama/nama_ibu_kandung sengaja ikut diperbarui dari yang baru saja
        // disampaikan/dikonfirmasi user di chat ini, karena setidaknya
        // sama valid dengan ejaan lama yang tersimpan (bisa jadi ejaan lama
        // itu sendiri yang typo).
        if ($candidate['source'] === 'local') {
            /** @var Patient $patient */
            $patient = $candidate['raw'];
            $patient->nama = $context['nama'] ?? $patient->nama;
            $patient->nama_ibu_kandung = $context['nama_ibu_kandung'] ?? $patient->nama_ibu_kandung;
            $patient->tempat_lahir = $context['tempat_lahir'] ?? $patient->tempat_lahir;
            $patient->no_hp = $noHp !== '-' ? $noHp : $patient->no_hp;
            $patient->last_synced_at = now();
            $patient->save();

            return ['awaiting_confirmation' => false, 'reply' => null, 'no_rm' => $patient->no_rm];
        }

        // Kandidat sumber GTK: logic upsert PERSIS sama dengan perilaku
        // lama (sebelum fitur pencocokan berlapis ini ada) - hanya lokasinya
        // yang berpindah ke method terpisah.
        $match = $candidate['raw'];
        $noRm = (string) $match['no_rm'];

        Patient::updateOrCreate(['no_rm' => $noRm], [
            'nama' => $match['nama'] ?? $context['nama'],
            'jk' => ($match['jeniskelamin'] ?? null) === 'L' ? 'LAKI-LAKI' : 'PEREMPUAN',
            'tanggal_lahir' => $match['tanggallahir'] ?? $context['tanggal_lahir'],
            'tempat_lahir' => $match['tempatlahir'] ?? $context['tempat_lahir'] ?? null,
            'nama_ibu_kandung' => $match['namaibu'] ?? $context['nama_ibu_kandung'],
            // Utamakan nomor yang baru saja dikonfirmasi orang tua di
            // chat ini ($noHp) daripada nomor lama yang sudah tercatat
            // di GTK ($match['nohp']) - itulah tujuan no_hp_dikonfirmasi:
            // catatan GTK bisa saja berisi nomor placeholder/basi dari
            // registrasi sebelumnya. Baru fallback ke punya GTK kalau
            // nomor sesi ini benar-benar tidak bisa ditentukan.
            'no_hp' => $noHp !== '-' ? $noHp : ($match['nohp'] ?? '-'),
            'alamat' => $match['alamat'] ?? null,
            'nik' => $match['nik'] ?? null,
            'last_synced_at' => now(),
        ]);

        return ['awaiting_confirmation' => false, 'reply' => null, 'no_rm' => $noRm];
    }

    /**
     * @return array{awaiting_confirmation: bool, reply: ?string, no_rm: ?string}
     */
    protected function createNewPatient(GtkApiService $gtk, array $context, string $noHp): array
    {
        $response = $gtk->tambahPasien([
            'nama' => $context['nama'],
            'jk' => $context['jenis_kelamin'],
            'tempat_lahir' => $context['tempat_lahir'] ?? '-',
            'tgl_lahir' => $context['tanggal_lahir'],
            'nama_ibu_kandung' => $context['nama_ibu_kandung'],
            'nama_pj' => $context['nama_ibu_kandung'],
            'no_hp' => $noHp,
            'tgl_daftar' => now()->toDateString(),
            'alamat_pasien' => $context['alamat'] ?? '-',
            'alamat_pj' => $context['alamat'] ?? '-',
        ]);

        $noRm = (string) $response['no_rkm_medis'];

        Patient::create([
            'no_rm' => $noRm,
            'nama' => $context['nama'],
            'jk' => $context['jenis_kelamin'],
            'tanggal_lahir' => $context['tanggal_lahir'],
            'tempat_lahir' => $context['tempat_lahir'] ?? null,
            'nama_ibu_kandung' => $context['nama_ibu_kandung'],
            'no_hp' => $noHp,
            'last_synced_at' => now(),
        ]);

        return ['awaiting_confirmation' => false, 'reply' => null, 'no_rm' => $noRm];
    }

    /**
     * @return array<int, array{no_rm: string, nama: ?string, tanggal_lahir: ?string, nama_ibu_kandung: ?string, no_hp: ?string, source: string, raw: array}>
     */
    protected function mapGtkCandidates(array $list): array
    {
        return array_map(fn (array $row) => [
            'no_rm' => (string) ($row['no_rm'] ?? ''),
            'nama' => $row['nama'] ?? null,
            'tanggal_lahir' => $this->normalizeGtkDate($row['tanggallahir'] ?? null),
            'nama_ibu_kandung' => $row['namaibu'] ?? null,
            'no_hp' => $row['nohp'] ?? null,
            'source' => 'gtk',
            'raw' => $row,
        ], $list);
    }

    /**
     * @param  Collection<int, Patient>  $patients
     * @return array<int, array{no_rm: string, nama: ?string, tanggal_lahir: ?string, nama_ibu_kandung: ?string, no_hp: ?string, source: string, raw: Patient}>
     */
    protected function mapLocalCandidates(Collection $patients): array
    {
        return $patients->map(fn (Patient $p) => [
            'no_rm' => $p->no_rm,
            'nama' => $p->nama,
            'tanggal_lahir' => $p->tanggal_lahir?->toDateString(),
            'nama_ibu_kandung' => $p->nama_ibu_kandung,
            'no_hp' => $p->no_hp,
            'source' => 'local',
            'raw' => $p,
        ])->values()->all();
    }

    protected function dedupeCandidatesByNoRm(array $candidates): array
    {
        $unique = [];

        foreach ($candidates as $candidate) {
            $unique[$candidate['no_rm']] ??= $candidate;
        }

        return array_values($unique);
    }

    /**
     * Field tanggallahir dari GTK belum pernah benar-benar dibandingkan ke
     * apapun sebelum fitur pencocokan berlapis ini (dulu hanya dipakai
     * sebagai parameter pencarian, tidak pernah dibaca balik dari response)
     * - jadi format wire persisnya belum pernah terverifikasi ketat.
     * Normalisasi defensif ke yyyy-mm-dd di sini supaya perbandingan exact-
     * match tanggal_lahir di PatientMatcher tidak diam-diam gagal total
     * hanya gara-gara GTK mengembalikan format lain (mis. dengan jam
     * "2021-01-01 00:00:00").
     */
    protected function normalizeGtkDate(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * STATE_2_KONFIRMASI - pilih poliklinik & shift, cek kuota, buat booking
     * atau waitlist (§3.1 langkah 4-5 & §3.2 PRD). Sekarang jadi fallback
     * tipis - jalur normal adalah handleStateOne() sudah mengumpulkan
     * jadwal kunjungan bersama formulir pendaftaran, tapi kalau entah
     * kenapa itu belum terisi saat STATE_1 selesai, STATE_2 tetap bisa
     * menanyakannya di sini lewat resolveSlotAndReply() yang sama persis.
     */
    protected function handleStateTwo(ChatSession $session, array $result, AntreanService $antrean, QuotaService $quota): string
    {
        if (! $result['ready_for_next_state']) {
            return $result['reply'];
        }

        return $this->resolveSlotAndReply($session, $session->context, $result, $antrean, $quota);
    }

    /**
     * Resolusi slot jadwal (verifikasi kuota REAL, bukan sekadar percaya
     * permintaan user) & pembuatan booking - dipakai dari DUA titik:
     * handleStateOne() (jalur normal sekarang, begitu formulir pendaftaran
     * STATE_1 lengkap TERMASUK jadwal kunjungan - lihat AiEngineService::
     * stateOnePrompt() "PENTING soal jadwal kunjungan") dan handleStateTwo()
     * (fallback di atas).
     */
    protected function resolveSlotAndReply(ChatSession $session, array $context, array $result, AntreanService $antrean, QuotaService $quota): string
    {
        $poliInput = $context['poli_pilihan'] ?? null;
        $shiftInput = $context['shift_pilihan'] ?? null;
        $tanggalInput = $context['tanggal_kunjungan'] ?? null;
        $tanggalDijawab = ($context['tanggal_kunjungan_dijawab'] ?? false) === true;

        if (! $poliInput || ! $shiftInput) {
            return $result['reply'];
        }

        // Jangan andalkan AI semata untuk memastikan pertanyaan tanggal
        // kunjungan sungguh-sungguh diajukan & dijawab user - tegakkan juga
        // di server (sama seperti pola poli_disetujui/no_hp_dikonfirmasi),
        // supaya model yang lupa/keliru langsung set ready_for_next_state
        // tanpa pernah menanyakan tanggal tidak bisa melompati konfirmasi ini.
        // Titik penjagaan TERAKHIR ini berlaku SAMA dari kedua pemanggil di
        // atas - handleStateOne() sendiri juga sudah menegakkan ini di
        // gate-nya sebelum pernah sampai ke sini.
        if (! $tanggalDijawab) {
            return $result['reply'];
        }

        // Sengaja TIDAK menunggu konfirmasi terpisah dari AI di sini
        // (dulu ada gate "tanggalSudahDijawabSebelumnya" + extracted.konfirmasi
        // yang memaksa satu giliran ekstra "ringkasan lalu konfirmasi" versi
        // AI) - itu menghasilkan DUA kali tanya-konfirmasi berturut-turut ke
        // user (versi AI, lalu versi server di bawah), padahal cuma perlu
        // satu. Begitu shift+tanggal terisi, langsung lanjut ke resolusi
        // slot - kalau tanggalnya diminta eksplisit oleh pasien sendiri,
        // hasil resolusi itu langsung dipakai booking TANPA giliran
        // konfirmasi tambahan lagi (pasien sudah menyatakannya sendiri di
        // formulir/balasannya); giliran konfirmasi via slot_ditawarkan HANYA
        // dipakai untuk cabang "secepatnya" (lihat komentar slot_ditawarkan
        // di bawah untuk alasannya).
        $tanggalRaw = is_string($tanggalInput) ? trim($tanggalInput) : null;
        $tanggalSecepatnya = $tanggalRaw !== null && strtolower($tanggalRaw) === 'secepatnya';

        if (! $tanggalSecepatnya && blank($tanggalRaw)) {
            // tanggal_kunjungan_dijawab true TAPI tanggal_kunjungan-nya
            // sendiri kosong - AI gagal mengisi kedua field ini bersamaan
            // (pernah kejadian nyata: akibatnya booking diam-diam jatuh ke
            // jadwal terdekat/hari ini, bukan tanggal yang sebenarnya
            // diminta pasien). JANGAN PERNAH menebak "secepatnya" di sini -
            // reset dijawab supaya AI wajib menanyakan ulang secara eksplisit,
            // bukan diam-diam membuat asumsi yang bisa salah.
            unset($context['tanggal_kunjungan_dijawab']);
            $session->context = $context;

            return 'Mohon konfirmasi tanggal kunjungan yang Anda inginkan (tanggal spesifik, atau "secepatnya" kalau tidak ada preferensi).';
        }

        // Cocokkan dua arah - AI kadang menyertakan kata tambahan (mis. "Poli
        // Fisioterapi Anak" untuk poliklinik yang di database cuma bernama
        // "Fisioterapi"), jadi jangan hanya cek nama_poliklinik yang memuat
        // poliInput, tapi juga sebaliknya.
        $poli = Poliklinik::query()
            ->where('nama_poliklinik', 'like', "%{$poliInput}%")
            ->orWhereRaw('? LIKE CONCAT(\'%\', nama_poliklinik, \'%\')', [$poliInput])
            ->first();

        if (! $poli) {
            $options = Poliklinik::query()->pluck('nama_poliklinik')->implode(', ');

            return "Maaf, poliklinik \"{$poliInput}\" tidak ditemukan. Poliklinik tersedia: {$options}";
        }

        $shift = Shift::tryFrom(strtolower((string) $shiftInput));

        if (! $shift) {
            return 'Mohon pilih shift: Pagi, Sore, atau Malam.';
        }

        // jenis_layanan sudah wajib divalidasi & dijawab di STATE_1
        // (handleStateOne()) sebelum sesi bisa sampai ke STATE_2 sama sekali
        // - re-validasi di sini murni jaring pengaman terakhir, sama seperti
        // Shift::tryFrom() di atas, BUKAN titik penegakan utamanya.
        $jenis = JenisLayanan::tryFrom((string) ($context['jenis_layanan'] ?? ''));

        if (! $jenis) {
            return 'Mohon konfirmasi ulang jenis layanan (Periksa Sakit/Imunisasi, Konsultasi Gizi, atau Konsultasi Tumbuh Kembang).';
        }

        if (! $tanggalSecepatnya) {
            $requestedDate = $this->parseTanggalKunjungan($tanggalRaw);

            if (! $requestedDate || $requestedDate->lt(Carbon::today())) {
                // Jangan biarkan tanggal yang tidak valid ini nyangkut di
                // context - kalau giliran berikutnya AI mengembalikan
                // tanggal_kunjungan null (mis. user cuma bilang "terserah"),
                // mergeContext() tidak akan menimpa nilai null di atas
                // extracted lama, jadi wajib dibersihkan manual di sini.
                // tanggal_kunjungan_dijawab juga wajib direset (walau sticky
                // true) supaya AI tahu harus tanya ulang, bukan menganggap
                // tanggal ini sudah final.
                unset($context['tanggal_kunjungan'], $context['tanggal_kunjungan_dijawab']);
                $session->context = $context;

                return 'Mohon sebutkan tanggal kunjungan yang valid, mulai hari ini atau setelahnya (mis. "20 Agustus 2026").';
            }

            $slot = $this->findSlotOnDate($poli->kode_poliklinik, $shift, $requestedDate, $quota, $jenis);

            if (! $slot) {
                unset($context['tanggal_kunjungan'], $context['tanggal_kunjungan_dijawab']);
                $session->context = $context;

                $alternatif = $this->findUpcomingDatesForShift($poli->kode_poliklinik, $shift, $requestedDate);

                if (empty($alternatif)) {
                    return "Maaf, poliklinik {$poli->nama_poliklinik} tidak memiliki jadwal untuk shift {$shift->label()}. "
                        .'Silakan pilih shift lain atau hubungi kami langsung.';
                }

                return "Maaf, tidak ada jadwal poliklinik {$poli->nama_poliklinik} shift {$shift->label()} pada tanggal "
                    .$requestedDate->toDateString().'. Tanggal terdekat yang tersedia: '
                    .implode(', ', $alternatif).'. Silakan pilih salah satu.';
            }
        } else {
            $slot = $this->findNearestSlot($poli->kode_poliklinik, $shift, $quota, $jenis);

            if (! $slot) {
                $availableShifts = collect(Shift::cases())
                    ->reject(fn (Shift $s) => $s === $shift)
                    ->filter(fn (Shift $s) => $this->findNearestSlot($poli->kode_poliklinik, $s, $quota, $jenis))
                    ->map(fn (Shift $s) => $s->label());

                if ($availableShifts->isEmpty()) {
                    return "Maaf, belum ada jadwal untuk poliklinik {$poli->nama_poliklinik} dalam waktu dekat di semua shift. "
                        .'Silakan coba lagi nanti atau hubungi kami langsung.';
                }

                return "Maaf, belum ada jadwal untuk poliklinik {$poli->nama_poliklinik} shift {$shift->label()} dalam waktu dekat. "
                    ."Shift yang tersedia: {$availableShifts->implode(', ')}. Silakan pilih salah satu.";
            }
        }

        // Kalau tanggal_kunjungan diminta EKSPLISIT oleh pasien sendiri
        // (bukan "secepatnya") dan slot PERSIS tanggal+shift itu tersedia
        // ($tanggalSecepatnya === false, cabang findSlotOnDate() di atas),
        // JANGAN tanya konfirmasi lagi - pasien sudah menyatakan sendiri
        // tanggal & shift ini (di formulir STATE_1 atau balasan eksplisitnya
        // di STATE_2), beda dari kejadian lama yang melatarbelakangi
        // mekanisme ini (AI diam-diam mengisi tanggal_kunjungan sendiri
        // tanpa pernah menanyakannya - itu sekarang sudah dicegah oleh gate
        // tanggal_kunjungan_dijawab di atas). Menanyakan ulang persetujuan
        // untuk sesuatu yang sudah persis mereka minta sendiri hanya jadi
        // konfirmasi ganda - langsung booking.
        // Konfirmasi TETAP dipakai HANYA untuk cabang "secepatnya"
        // (findNearestSlot() di atas) - di situ SISTEM yang memilihkan
        // tanggal/dokternya, bukan pasien, jadi mereka belum pernah
        // benar-benar melihat/menyetujui tanggal spesifik yang dipilihkan
        // sebelum booking dibuat; giliran TERPISAH via slot_ditawarkan tetap
        // wajib di sini.
        if ($tanggalSecepatnya) {
            $slotKey = $slot['tanggal'].'|'.$slot['kode_dokter'].'|'.$shift->value;

            if (($context['slot_ditawarkan'] ?? null) !== $slotKey) {
                $context['slot_ditawarkan'] = $slotKey;
                $session->context = $context;

                $tanggalLabel = Carbon::parse($slot['tanggal'])->translatedFormat('d F Y');
                $jamLabel = $this->formatJamRange($slot['jam_mulai'], $slot['jam_selesai']);

                return "Untuk poliklinik {$poli->nama_poliklinik}, jadwal yang tersedia adalah "
                    ."{$tanggalLabel} shift {$shift->label()} pukul {$jamLabel}. Apakah Bunda/Ayah setuju dengan jadwal ini? "
                    .'Balas "Ya" untuk konfirmasi, atau beri tahu kami kalau ingin tanggal/shift lain.';
            }

            unset($context['slot_ditawarkan']);
            $session->context = $context;
        }

        $booking = $antrean->createBooking($session, [
            'no_rm' => $session->no_rm,
            'kode_poliklinik' => $poli->kode_poliklinik,
            'kode_dokter' => $slot['kode_dokter'],
            'tanggal_periksa' => $slot['tanggal'],
            'shift' => $shift,
            'jenis_layanan' => $jenis,
        ]);

        $session->state = ChatState::Done->value;
        $session->step = null;

        if ($booking->status === BookingStatus::Waitlist) {
            // Posisi antrean tunggu dipisah per jenis_layanan (lihat
            // AntreanService::nextWaitlistPosition()) - sertakan label
            // kategori supaya "posisi #1" tidak terbaca aneh kalau pasien
            // lain kategori berbeda kebetulan juga "posisi #1" di shift yang
            // sama persis.
            $pesan = "Kuota {$jenis->label()} shift {$shift->label()} (pukul {$this->formatJamRange($slot['jam_mulai'], $slot['jam_selesai'])}) "
                ."pada {$slot['tanggal']} sudah penuh. Anda dimasukkan ke daftar tunggu "
                ."{$jenis->label()} (posisi #{$booking->waitlist_position}). Kami akan menghubungi Anda jika ada slot tersedia.";

            // §3.2 PRD: tawarkan jadwal dokter yang sama di tanggal/shift lain
            // yang kuotanya masih tersedia, supaya user tidak cuma pasrah
            // menunggu antrean - AntreanService::offerAlternative() sebelumnya
            // sudah ada tapi belum pernah dipanggil dari alur booking.
            $alternatif = $antrean->offerAlternative($slot['kode_dokter'], $slot['tanggal'], $jenis);

            if (! empty($alternatif)) {
                $daftar = collect($alternatif)
                    ->map(fn (array $a) => $a['tanggal'].' shift '.Shift::from($a['shift'])->label())
                    ->implode(', ');

                $pesan .= "\n\nAtau kalau ingin lebih cepat, ada jadwal tersedia di: {$daftar}. "
                    .'Balas tanggal/shift yang Anda mau kalau ingin pindah ke jadwal itu.';
            }

            return $pesan;
        }

        $namaDokter = Doctor::query()->where('kode_dokter', $slot['kode_dokter'])->value('nama_dokter') ?? $slot['kode_dokter'];
        $tanggalLabel = Carbon::parse($slot['tanggal'])->translatedFormat('d F Y');
        $jamLabel = $this->formatJamRange($slot['jam_mulai'], $slot['jam_selesai']);

        return "Terima kasih Ayah/Bunda.\n"
            ."Adik {$context['nama']} telah terdaftar di jadwal {$jenis->label()} {$namaDokter} pada {$tanggalLabel}, pukul {$jamLabel}.\n\n"
            .'Untuk nomor antrean akan disesuaikan dengan kedatangan di Graha Tumbuh Kembang.'
            ."\n\nGraha Tumbuh Kembang\n"
            .'Solusi Kesehatan & Tumbuh Kembang Anak';
    }

    /**
     * DoctorSchedule::jam_mulai/jam_selesai tersimpan dengan detik (mis.
     * "14:00:00") - pasien tidak perlu lihat detiknya, cukup HH:MM.
     */
    protected function formatJamRange(string $jamMulai, string $jamSelesai): string
    {
        return substr($jamMulai, 0, 5).'-'.substr($jamSelesai, 0, 5);
    }

    /**
     * ISO-8601 dayOfWeekIso (1 = Senin ... 7 = Minggu) <-> nama hari yang
     * dipakai kolom DoctorSchedule::hari.
     */
    protected const HARI_BY_ISO = [
        1 => 'SENIN', 2 => 'SELASA', 3 => 'RABU', 4 => 'KAMIS',
        5 => 'JUMAT', 6 => 'SABTU', 7 => 'MINGGU',
    ];

    /**
     * Cari dokter + tanggal terdekat pada poliklinik & shift yang dipilih,
     * dipakai saat user tidak punya preferensi tanggal. Ketersediaan kuota
     * ditangani oleh AntreanService (waitlist otomatis jika penuh), sesuai
     * §3.2 PRD.
     *
     * @return array{tanggal: string, kode_dokter: string, jam_mulai: string, jam_selesai: string}|null
     */
    protected function findNearestSlot(string $kodePoliklinik, Shift $shift, QuotaService $quota, JenisLayanan $jenis): ?array
    {
        $hariOrder = array_flip(self::HARI_BY_ISO);

        // Urutkan by jam_mulai supaya kalau beberapa dokter berbeda kebetulan
        // sama-sama berlabel shift ini di hari yang sama (nyata terjadi -
        // mis. shift "sore" dipecah 14:00-17:00 & 17:00-19:00 untuk dokter
        // berbeda), calon TERURUT deterministik (bukan sekadar urutan baris
        // DB yang tidak berarti apa-apa) sebelum dipilah lagi berdasar kuota.
        //
        // source='manual' WAJIB - jadwal hasil sync GTK tidak lagi dipakai
        // untuk booking sama sekali (kejadian nyata: dr. Retno punya baris
        // manual 15:30-17:00 DAN baris gtk basi/dokter gtk lain di
        // poliklinik yang sama untuk shift "sore" mulai 14:00 - tanpa filter
        // ini, bot bisa menawarkan jadwal gtk itu alih-alih jadwal dashboard
        // yang benar). Dashboard (jadwal.store/update) adalah SATU-SATUNYA
        // sumber kebenaran jadwal sekarang.
        $schedules = DoctorSchedule::query()
            ->where('kode_poliklinik', $kodePoliklinik)
            ->where('shift', $shift->value)
            ->where('source', 'manual')
            ->orderBy('jam_mulai')
            ->get();

        if ($schedules->isEmpty()) {
            return null;
        }

        $today = Carbon::today();
        $candidates = [];

        foreach ($schedules as $schedule) {
            $targetDow = $hariOrder[$schedule->hari] ?? null;

            if (! $targetDow) {
                continue;
            }

            $diff = ($targetDow - $today->dayOfWeekIso + 7) % 7;
            $date = $today->copy()->addDays($diff);
            $candidates[] = [
                'date' => $date,
                'kode_dokter' => $schedule->kode_dokter,
                'jam_mulai' => $schedule->jam_mulai,
                'jam_selesai' => $schedule->jam_selesai,
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        $nearestDate = collect($candidates)->min('date');
        $sameDay = collect($candidates)->filter(fn (array $c) => $c['date']->equalTo($nearestDate));

        // Kalau beberapa dokter sama-sama jadwal di tanggal terdekat ini,
        // JANGAN asal pilih yang pertama ketemu - utamakan yang kuotanya
        // masih tersedia (lihat catatan orderBy jam_mulai di atas untuk
        // kenapa kasus ini nyata terjadi), supaya user tidak "beruntungan"
        // diarahkan ke dokter yang kebetulan lebih dulu di query padahal
        // sudah penuh sementara dokter lain di jam berbeda masih longgar.
        $chosen = $sameDay->first(
            fn (array $c) => $quota->hasAvailability($c['kode_dokter'], $nearestDate->toDateString(), $shift, $jenis)
        ) ?? $sameDay->first();

        return [
            'tanggal' => $nearestDate->toDateString(),
            'kode_dokter' => $chosen['kode_dokter'],
            'jam_mulai' => $chosen['jam_mulai'],
            'jam_selesai' => $chosen['jam_selesai'],
        ];
    }

    /**
     * Normalisasi input tanggal_kunjungan (idealnya sudah yyyy-mm-dd dari
     * AI, tapi divalidasi ulang di server sebagai jaring pengaman terakhir -
     * sama seperti pola validasi no_hp di handleStateOne()).
     */
    protected function parseTanggalKunjungan(string $tanggal): ?Carbon
    {
        try {
            return Carbon::parse($tanggal)->startOfDay();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Cari dokter yang terjadwal pada poliklinik & shift TEPAT di tanggal
     * yang diminta user (bukan tanggal terdekat) - dipakai saat user
     * menyebutkan preferensi tanggal kunjungan sendiri. Kuota per tanggal
     * ini baru divalidasi belakangan oleh AntreanService::createBooking()
     * (fallback ke waitlist jika penuh, atau kalau tanggalnya di luar
     * jendela sinkronisasi quota_shifts §3.2 PRD) - di sini kita hanya
     * mengonfirmasi bahwa poliklinik memang buka di hari itu.
     *
     * @return array{tanggal: string, kode_dokter: string, jam_mulai: string, jam_selesai: string}|null
     */
    protected function findSlotOnDate(string $kodePoliklinik, Shift $shift, Carbon $date, QuotaService $quota, JenisLayanan $jenis): ?array
    {
        $hari = self::HARI_BY_ISO[$date->dayOfWeekIso] ?? null;

        // Ambil SEMUA dokter yang cocok, bukan cuma first() - pernah kejadian
        // nyata lebih dari satu dokter berbeda berbagi label shift yang sama
        // pada hari yang sama (mis. shift "sore" dipecah 14:00-17:00 &
        // 17:00-19:00 untuk dokter berbeda). Tanpa ini, first() memilih
        // dokter secara arbitrer (tergantung urutan baris DB, bukan
        // keputusan yang berarti) - user bisa "beruntungan" diarahkan ke
        // dokter yang kuotanya sudah penuh sementara dokter lain masih
        // longgar. Urutkan by jam_mulai untuk determinisme, lalu utamakan
        // yang kuotanya masih tersedia.
        $schedules = DoctorSchedule::query()
            ->where('kode_poliklinik', $kodePoliklinik)
            ->where('shift', $shift->value)
            ->where('hari', $hari)
            ->where('source', 'manual')
            ->orderBy('jam_mulai')
            ->get();

        if ($schedules->isEmpty()) {
            return null;
        }

        $tanggal = $date->toDateString();
        $schedule = $schedules->first(
            fn (DoctorSchedule $s) => $quota->hasAvailability($s->kode_dokter, $tanggal, $shift, $jenis)
        ) ?? $schedules->first();

        return [
            'tanggal' => $tanggal,
            'kode_dokter' => $schedule->kode_dokter,
            'jam_mulai' => $schedule->jam_mulai,
            'jam_selesai' => $schedule->jam_selesai,
        ];
    }

    /**
     * Tawarkan beberapa tanggal terdekat (mulai dari $from) yang poliklinik
     * & shift-nya punya jadwal, dipakai saat tanggal yang diminta user tidak
     * tersedia sama sekali.
     *
     * @return array<int, string>
     */
    protected function findUpcomingDatesForShift(string $kodePoliklinik, Shift $shift, Carbon $from, int $limit = 3, int $horizonDays = 60): array
    {
        $hariTersedia = DoctorSchedule::query()
            ->where('kode_poliklinik', $kodePoliklinik)
            ->where('shift', $shift->value)
            ->where('source', 'manual')
            ->pluck('hari')
            ->unique();

        if ($hariTersedia->isEmpty()) {
            return [];
        }

        $dates = [];
        $cursor = $from->copy();

        for ($i = 0; $i < $horizonDays && count($dates) < $limit; $i++) {
            $hari = self::HARI_BY_ISO[$cursor->dayOfWeekIso] ?? null;

            if ($hari && $hariTersedia->contains($hari)) {
                $dates[] = $cursor->toDateString();
            }

            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * STATE_3_DONE - respon lanjutan (§6.A PRD), termasuk pembatalan
     * inisiatif pasien lewat chat.
     */
    protected function handleStateThree(ChatSession $session, array $result, AntreanService $antrean): string
    {
        $intent = $result['extracted']['intent'] ?? null;

        if ($intent === 'batal') {
            $booking = $session->bookings()
                ->whereIn('status', [BookingStatus::Booked->value, BookingStatus::Confirmed->value, BookingStatus::Waitlist->value])
                ->latest()
                ->first();

            if (! $booking) {
                return 'Anda tidak memiliki jadwal aktif untuk dibatalkan.';
            }

            $antrean->cancelBooking($booking, 'Dibatalkan oleh pasien via chat');

            return 'Baik, jadwal kunjungan Anda telah dibatalkan.';
        }

        // Pasien yang sama minta didaftarkan untuk keluhan/kunjungan baru
        // setelah booking sebelumnya selesai. JANGAN biarkan AI "berimprovisasi"
        // menjalankan seluruh alur booking lewat teks bebas di STATE_3 (tidak
        // ada klasifikasi poli/pencarian jadwal/pembuatan booking nyata di sini)
        // - kembalikan sesi ke STATE_1 supaya alur deterministik lengkap
        // (klasifikasi -> konfirmasi data -> pilih shift -> booking nyata)
        // berjalan lagi dari awal untuk kunjungan ini.
        if ($intent === 'kunjungan_baru') {
            $context = $session->context ?? [];

            // poli_disetujui, tanggal_kunjungan_dijawab, & jenis_layanan_dijawab
            // termasuk STICKY_TRUE_KEYS (sekali true, mergeContext() tidak akan
            // pernah menimpanya balik ke false) - semuanya atribut PER-KUNJUNGAN,
            // bukan permanen per-pasien, jadi WAJIB direset manual di sini. Tanpa
            // ini, AI mengira poli baru sudah disetujui & tanggal kunjungan baru
            // sudah dijawab (lihat instruksi STATE_2 di AiEngineService::
            // stateTwoPrompt), lalu memakai tanggal_kunjungan KUNJUNGAN SEBELUMNYA
            // untuk booking kunjungan ini - begitu pula jenis_layanan: kunjungan
            // baru bisa saja kategorinya beda dari kunjungan sebelumnya (mis. dulu
            // pemeriksaan, sekarang cuma konsultasi kontrol), jadi kalau tidak
            // direset di sini booking kedua akan diam-diam memakai pool kuota
            // kunjungan pertama tanpa pernah benar-benar ditanyakan ulang.
            // slot_ditawarkan juga wajib dibersihkan - kalau tidak, slot lama
            // yang kebetulan cocok lagi bisa lolos gerbang konfirmasi tanpa
            // pernah benar-benar ditawarkan ulang untuk kunjungan baru ini.
            unset(
                $context['poli_pilihan'], $context['poli_disetujui'],
                $context['shift_pilihan'],
                $context['jenis_layanan'], $context['jenis_layanan_dijawab'],
                $context['tanggal_kunjungan'], $context['tanggal_kunjungan_dijawab'],
                $context['slot_ditawarkan'],
            );
            $session->context = $context;
            $session->state = ChatState::PengumpulanData->value;
            $session->step = null;

            $nama = $context['nama'] ?? 'ananda';

            return "Baik, akan kami bantu proses pendaftaran kunjungan baru untuk {$nama}.";
        }

        return $result['reply'];
    }
}
