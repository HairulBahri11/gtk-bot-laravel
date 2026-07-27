<?php

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Enums\ChatState;
use App\Enums\Shift;
use App\Models\ChatSession;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Poliklinik;
use App\Models\WhatsappMessage;
use App\Services\Ai\AiEngineException;
use App\Services\Ai\AiEngineService;
use App\Services\Antrean\AntreanService;
use App\Services\Gtk\GtkApiException;
use App\Services\Gtk\GtkApiService;
use App\Services\Whatsapp\WhatsAppServiceInterface;
use App\Support\IndonesianPhoneNumber;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
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

    public function __construct(public string $chatId, public string $text)
    {
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->chatId))->expireAfter(120)->dontRelease()];
    }

    public function handle(
        AiEngineService $ai,
        GtkApiService $gtk,
        AntreanService $antrean,
        WhatsAppServiceInterface $wa,
    ): void {
        $session = ChatSession::firstOrCreate(
            ['chat_id' => $this->chatId],
            ['state' => ChatState::PengumpulanData->value, 'context' => []],
        );
        $session->last_message_at = now();

        $this->autoFillPhoneFromChatId($session);

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
                ChatState::PengumpulanData => $this->handleStateOne($session, $result, $gtk),
                ChatState::Konfirmasi => $this->handleStateTwo($session, $result, $antrean),
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
        $sameNama = Str::lower(trim($newNama)) === Str::lower(trim((string) ($context['nama'] ?? '')));
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

    protected function mergeContext(ChatSession $session, array $extracted): void
    {
        $context = $session->context ?? [];

        foreach ($extracted as $key => $value) {
            if ($value !== null && $value !== '' && ! in_array($key, ['konfirmasi', 'intent'], true)) {
                // Sekali disetujui, jangan biarkan giliran berikutnya
                // membalikkannya ke false - lihat catatan di handleStateOne().
                if ($key === 'poli_disetujui' && ($context['poli_disetujui'] ?? false) === true) {
                    continue;
                }

                $context[$key] = $value;
            }
        }

        $session->context = $context;
    }

    protected function reply(WhatsAppServiceInterface $wa, string $message): void
    {
        $wa->sendText($this->chatId, $message);

        WhatsappMessage::create([
            'chat_id' => $this->chatId,
            'direction' => 'out',
            'message' => $message,
        ]);
    }

    /**
     * STATE_1_PENGUMPULAN_DATA - §6.A PRD: tidak melompat ke booking sebelum
     * nama, tanggal lahir, nama ibu, jenis kelamin, dan keluhan lengkap.
     */
    protected function handleStateOne(ChatSession $session, array $result, GtkApiService $gtk): string
    {
        $context = $session->context;

        // no_hp bisa juga terisi dari jawaban bebas user (bukan cuma auto-fill
        // dari chat_id yang sudah pasti valid) - validasi ulang di sini sebagai
        // nomor seluler Indonesia yang sah. Kalau tidak valid, kosongkan lagi
        // supaya field ini dianggap belum terisi & AI menanyakannya ulang.
        if (filled($context['no_hp'] ?? null)) {
            $normalizedNoHp = IndonesianPhoneNumber::normalize((string) $context['no_hp']);

            if ($normalizedNoHp === null) {
                unset($context['no_hp']);
            } else {
                $context['no_hp'] = $normalizedNoHp;
            }

            $session->context = $context;
        }

        $required = ['nama', 'tanggal_lahir', 'nama_ibu_kandung', 'jenis_kelamin', 'no_hp', 'keluhan'];
        $complete = collect($required)->every(fn ($field) => filled($context[$field] ?? null));

        // Jangan andalkan ready_for_next_state semata untuk syarat persetujuan
        // poliklinik - tegakkan juga di sisi server memakai flag poli_disetujui
        // yang tersimpan permanen di context (lihat AiEngineService::stateOnePrompt),
        // supaya model yang lupa/keliru menandai giliran pertama sebagai "sudah
        // setuju" tidak bisa melompati konfirmasi ini.
        $poliDisetujui = blank($context['poli_pilihan'] ?? null) || ($context['poli_disetujui'] ?? false) === true;

        if (! $complete || ! $poliDisetujui || ! $result['ready_for_next_state']) {
            return $result['reply'];
        }

        $noRm = $this->resolvePatient($session, $gtk, $context);
        $session->no_rm = $noRm;
        $session->state = ChatState::Konfirmasi->value;
        $session->step = 'pilih_poli_shift';

        // poli_pilihan biasanya sudah terisi dari hasil klasifikasi keluhan
        // di STATE_1 (lihat AiEngineService::stateOnePrompt) - jangan minta
        // pilih ulang, cukup minta shift + konfirmasi akhir.
        if (filled($context['poli_pilihan'] ?? null)) {
            return "Terima kasih. Data {$context['nama']} sudah tersimpan (No. RM: {$noRm}).\n\n"
                ."Untuk layanan {$context['poli_pilihan']}, silakan pilih shift kunjungan (Pagi/Sore/Malam).";
        }

        $poliOptions = Poliklinik::query()->where('is_active', true)->pluck('nama_poliklinik')->implode(', ');

        return "Terima kasih. Data {$context['nama']} sudah tersimpan (No. RM: {$noRm}).\n\n"
            ."Silakan pilih poliklinik dan shift kunjungan (Pagi/Sore/Malam).\n"
            ."Poliklinik tersedia: {$poliOptions}";
    }

    protected function resolvePatient(ChatSession $session, GtkApiService $gtk, array $context): string
    {
        // no_hp normalnya sudah terisi lewat autoFillPhoneFromChatId() atau
        // hasil tanya-jawab AI. Tetap divalidasi/normalisasi ulang di sini
        // sebagai jaring pengaman terakhir - JANGAN PERNAH pakai potongan
        // chat_id mentah, karena kontak berformat "...@lid" tidak mengekspos
        // nomor asli sama sekali (lihat IndonesianPhoneNumber).
        $noHp = IndonesianPhoneNumber::normalize($context['no_hp'] ?? null)
            ?? IndonesianPhoneNumber::fromChatId($session->chat_id)
            ?? '-';

        try {
            $found = $gtk->cariPasien([
                'nama' => $context['nama'],
                'tanggal_lahir' => $context['tanggal_lahir'],
            ]);
            $match = $found['list'][0] ?? null;
        } catch (GtkApiException $e) {
            $match = null;
        }

        if ($match) {
            $noRm = (string) $match['no_rm'];

            Patient::updateOrCreate(['no_rm' => $noRm], [
                'nama' => $match['nama'] ?? $context['nama'],
                'jk' => ($match['jeniskelamin'] ?? null) === 'L' ? 'LAKI-LAKI' : 'PEREMPUAN',
                'tanggal_lahir' => $match['tanggallahir'] ?? $context['tanggal_lahir'],
                'nama_ibu_kandung' => $match['namaibu'] ?? $context['nama_ibu_kandung'],
                'no_hp' => $match['nohp'] ?? $noHp,
                'alamat' => $match['alamat'] ?? null,
                'nik' => $match['nik'] ?? null,
                'last_synced_at' => now(),
            ]);

            return $noRm;
        }

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
            'nama_ibu_kandung' => $context['nama_ibu_kandung'],
            'no_hp' => $noHp,
            'last_synced_at' => now(),
        ]);

        return $noRm;
    }

    /**
     * STATE_2_KONFIRMASI - pilih poliklinik & shift, cek kuota, buat booking
     * atau waitlist (§3.1 langkah 4-5 & §3.2 PRD).
     */
    protected function handleStateTwo(ChatSession $session, array $result, AntreanService $antrean): string
    {
        $context = $session->context;
        $confirmed = (bool) ($result['extracted']['konfirmasi'] ?? false);

        if (! $confirmed || ! $result['ready_for_next_state']) {
            return $result['reply'];
        }

        $poliInput = $context['poli_pilihan'] ?? null;
        $shiftInput = $context['shift_pilihan'] ?? null;

        if (! $poliInput || ! $shiftInput) {
            return $result['reply'];
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

        $slot = $this->findNearestSlot($poli->kode_poliklinik, $shift);

        if (! $slot) {
            $availableShifts = collect(Shift::cases())
                ->reject(fn (Shift $s) => $s === $shift)
                ->filter(fn (Shift $s) => $this->findNearestSlot($poli->kode_poliklinik, $s))
                ->map(fn (Shift $s) => $s->label());

            if ($availableShifts->isEmpty()) {
                return "Maaf, belum ada jadwal untuk poliklinik {$poli->nama_poliklinik} dalam waktu dekat di semua shift. "
                    .'Silakan coba lagi nanti atau hubungi kami langsung.';
            }

            return "Maaf, belum ada jadwal untuk poliklinik {$poli->nama_poliklinik} shift {$shift->label()} dalam waktu dekat. "
                ."Shift yang tersedia: {$availableShifts->implode(', ')}. Silakan pilih salah satu.";
        }

        $booking = $antrean->createBooking($session, [
            'no_rm' => $session->no_rm,
            'kode_poliklinik' => $poli->kode_poliklinik,
            'kode_dokter' => $slot['kode_dokter'],
            'tanggal_periksa' => $slot['tanggal'],
            'shift' => $shift,
        ]);

        $session->state = ChatState::Done->value;
        $session->step = null;

        if ($booking->status === BookingStatus::Waitlist) {
            return "Kuota shift {$shift->label()} pada {$slot['tanggal']} sudah penuh. Anda dimasukkan ke daftar tunggu "
                ."(posisi #{$booking->waitlist_position}). Kami akan menghubungi Anda jika ada slot tersedia.";
        }

        return "Booking berhasil!\n"
            ."Poliklinik: {$poli->nama_poliklinik}\n"
            ."Tanggal: {$slot['tanggal']}\n"
            ."Shift: {$shift->label()}\n"
            ."No. Rawat: {$booking->no_rawat}\n\n"
            .'Kami akan mengirim pengingat H-1, 3 jam, dan 1 jam sebelum jadwal.';
    }

    /**
     * Cari dokter + tanggal terdekat pada poliklinik & shift yang dipilih.
     * Ketersediaan kuota ditangani oleh AntreanService (waitlist otomatis
     * jika penuh), sesuai §3.2 PRD.
     *
     * @return array{tanggal: string, kode_dokter: string}|null
     */
    protected function findNearestSlot(string $kodePoliklinik, Shift $shift): ?array
    {
        $hariOrder = [
            'SENIN' => 1, 'SELASA' => 2, 'RABU' => 3, 'KAMIS' => 4,
            'JUMAT' => 5, 'SABTU' => 6, 'MINGGU' => 7,
        ];

        $schedules = DoctorSchedule::query()
            ->where('kode_poliklinik', $kodePoliklinik)
            ->where('shift', $shift->value)
            ->get();

        if ($schedules->isEmpty()) {
            return null;
        }

        $today = Carbon::today();
        $best = null;

        foreach ($schedules as $schedule) {
            $targetDow = $hariOrder[$schedule->hari] ?? null;

            if (! $targetDow) {
                continue;
            }

            $diff = ($targetDow - $today->dayOfWeekIso + 7) % 7;
            $date = $today->copy()->addDays($diff);

            if (! $best || $date->lt($best['date'])) {
                $best = ['date' => $date, 'kode_dokter' => $schedule->kode_dokter];
            }
        }

        return $best ? ['tanggal' => $best['date']->toDateString(), 'kode_dokter' => $best['kode_dokter']] : null;
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
            unset($context['poli_pilihan'], $context['shift_pilihan']);
            $session->context = $context;
            $session->state = ChatState::PengumpulanData->value;
            $session->step = null;

            $nama = $context['nama'] ?? 'ananda';

            return "Baik, akan kami bantu proses pendaftaran kunjungan baru untuk {$nama}.";
        }

        return $result['reply'];
    }
}
