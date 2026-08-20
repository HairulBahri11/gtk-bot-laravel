<?php

namespace App\Console\Commands;

use App\Enums\JenisLayanan;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Evaluasi window H-1 hari / H-3 jam / H-1 jam, DAN reminder mingguan H-7
 * (lihat dispatchWeeklyReminders()) utk tiap kunjungan aktif di
 * kunjungan_reminder (Supabase, diisi LANGSUNG oleh KunjunganReminderService
 * saat booking dibuat/dipindah - BUKAN disinkronkan dari GTK, lihat
 * routes/console.php), kirim WA lewat WAHA, lalu update status + tulis
 * audit ke reminder_log. Idempotent (dijaga kolom reminder_*_status per
 * kunjungan+jenis), proses baca+kunci baris dibungkus 1 transaksi (FOR
 * UPDATE SKIP LOCKED) supaya aman kalau invocation sebelumnya belum selesai
 * saat yang berikutnya mulai.
 *
 * Menggantikan pendekatan Supabase Edge Function
 * (supabase/functions/dispatch-reminders) - dijalankan sebagai command
 * Laravel biasa supaya tidak perlu deploy/kelola apa pun di sisi Supabase
 * selain tabelnya. Dipanggil WAHA langsung (bukan lewat
 * WhatsAppServiceInterface) karena butuh tahu sukses/gagalnya tiap
 * pengiriman utk ditulis ke reminder_log - interface itu sengaja
 * fire-and-forget (void) utk pemakaian lain di aplikasi ini.
 */
class DispatchKunjunganReminder extends Command
{
    protected $signature = 'gtk:dispatch-kunjungan-reminder
        {--filter= : Batasi ke nama_pasien yang mengandung teks ini (LIKE, case-insensitive) - utk testing terbatas tanpa kirim ke semua pasien}';

    protected $description = 'Evaluasi window reminder H-1 hari/3 jam/1 jam + mingguan H-7/14/21/dst & kirim WA (tabel kunjungan_reminder di Supabase)';

    /**
     * Kata "besok"/"hari ini" yang dipakai di badan pesan (lihat
     * buildMessage()) - H-1 hari betul-betul besok, sedangkan H-3 jam/H-1
     * jam sama-sama masih di HARI YANG SAMA (cuma beda seberapa dekat jam
     * kunjungannya), jadi keduanya WAJIB bilang "hari ini", BUKAN "besok".
     *
     * @var array<string, string>
     */
    protected array $labels = [
        'h1hari' => 'besok',
        'h3jam' => 'hari ini',
        'h1jam' => 'hari ini',
    ];

    public function handle(): int
    {
        $connection = DB::connection('pgsql');
        $filter = $this->option('filter');
        $namaLike = $filter !== null ? "%{$filter}%" : '%';

        if ($filter !== null) {
            $this->warn("Mode testing aktif: hanya memproses nama_pasien yang mengandung \"{$filter}\".");
        }

        $sent = 0;
        $skipped = 0;
        $expired = 0;
        $candidates = 0;

        // Jadwal sudah lewat tapi masih 'pending' (mis. command sempat tidak
        // jalan beberapa jam) - jangan kirim reminder basi, tandai expired.
        foreach (array_keys($this->labels) as $kind) {
            $statusCol = "reminder_{$kind}_status";

            $expired += $connection->table('kunjungan_reminder')
                ->where('status_kunjungan', 'active')
                ->where($statusCol, 'pending')
                ->where('nama_pasien', 'ilike', $namaLike)
                ->whereRaw("jadwal_at <= (now() at time zone 'Asia/Jakarta')")
                ->update([$statusCol => 'expired']);
        }

        $connection->transaction(function () use ($connection, &$sent, &$skipped, &$candidates, $namaLike) {
            $rows = $connection->select(<<<'SQL'
                select *,
                  -- Kejadian nyata (bug): kunjungan yang DIBUAT hari ini
                  -- UNTUK hari ini juga (jarak ke jadwal_at < 24 jam murni
                  -- karena sama-sama hari ini, BUKAN karena besok sudah
                  -- dekat) sempat lolos due_h1hari & terkirim reminder
                  -- berlabel "besok" - padahal kunjungannya hari ini.
                  -- Wajib DUA syarat sekaligus: jarak <= 24 jam DAN
                  -- jadwal_at jatuh di TANGGAL KALENDER setelah hari ini -
                  -- kalau kunjungannya sendiri hari ini, h1hari tidak
                  -- pernah relevan sama sekali (bukan cuma ditunda).
                  (jadwal_at - (now() at time zone 'Asia/Jakarta')) <= interval '24 hours'
                    and jadwal_at::date > (now() at time zone 'Asia/Jakarta')::date as due_h1hari,
                  (jadwal_at - (now() at time zone 'Asia/Jakarta')) <= interval '3 hours'  as due_h3jam,
                  (jadwal_at - (now() at time zone 'Asia/Jakarta')) <= interval '1 hour'   as due_h1jam
                from kunjungan_reminder
                where status_kunjungan = 'active'
                  and nama_pasien ilike ?
                  and jadwal_at > (now() at time zone 'Asia/Jakarta')
                  and jadwal_at - (now() at time zone 'Asia/Jakarta') <= interval '25 hours'
                order by jadwal_at
                for update skip locked
                SQL,
                [$namaLike]
            );

            $candidates = count($rows);

            foreach ($rows as $row) {
                foreach ($this->labels as $kind => $label) {
                    $statusCol = "reminder_{$kind}_status";
                    $sentAtCol = "reminder_{$kind}_sent_at";
                    $dueField = "due_{$kind}";

                    if ($row->$statusCol !== 'pending' || ! $row->$dueField) {
                        continue;
                    }

                    if (! $row->nohp) {
                        $connection->table('kunjungan_reminder')
                            ->where('no_rawat', $row->no_rawat)
                            ->update([$statusCol => 'skipped']);

                        $connection->table('reminder_log')->insert([
                            'no_rawat' => $row->no_rawat,
                            'jenis_reminder' => $kind,
                            'status' => 'failed',
                            'error_message' => 'Nomor HP kosong/tidak valid',
                        ]);

                        $skipped++;

                        continue;
                    }

                    [$ok, $responseBody] = $this->sendWaha("{$row->nohp}@c.us", $this->buildMessage($kind, $label, $row));

                    $update = [$statusCol => $ok ? 'sent' : 'failed'];

                    if ($ok) {
                        $update[$sentAtCol] = now();
                    }

                    $connection->table('kunjungan_reminder')->where('no_rawat', $row->no_rawat)->update($update);

                    $connection->table('reminder_log')->insert([
                        'no_rawat' => $row->no_rawat,
                        'jenis_reminder' => $kind,
                        'status' => $ok ? 'success' : 'failed',
                        'gateway_response' => json_encode($responseBody),
                        'error_message' => $ok ? null : json_encode($responseBody),
                    ]);

                    if ($ok) {
                        $sent++;
                    }
                }
            }
        });

        [$sentH7, $candidatesH7] = $this->dispatchWeeklyReminders($connection, $namaLike);

        $this->info("Dispatch selesai: {$candidates} kandidat, {$sent} terkirim, {$skipped} di-skip, {$expired} expired. "
            ."Mingguan (H-7/14/21/dst): {$candidatesH7} kandidat, {$sentH7} terkirim.");

        return self::SUCCESS;
    }

    /**
     * Reminder mingguan untuk kunjungan yang jaraknya JAUH dari tanggal
     * pendaftaran (> 7 hari, bukan >= - kalau pasien daftar PERSIS 7 hari
     * sebelum kunjungan, checkpoint H-7 akan langsung "jatuh tempo" pada
     * hari pendaftaran itu sendiri, yang tidak masuk akal dikirim ke
     * pasien yang baru saja daftar). "Tanggal pendaftaran" diambil dari
     * created_at baris kunjungan_reminder ini sendiri, yang dibuat oleh
     * KunjunganReminderService::upsertForBooking() tepat saat SATU no_rawat
     * tertentu pertama kali dibuat. Perhatikan: cascading reschedule
     * (AntreanService::moveBookingToShift(), dipicu dokter batal/delay
     * shift) SELALU membatalkan kunjungan_reminder lama & membuat baris
     * BARU untuk no_rawat baru (bukan update baris lama) - jadi utk
     * kunjungan yang pernah dipindah, "tanggal pendaftaran" di sini efektif
     * jadi tanggal PEMINDAHAN-nya, bukan pendaftaran pertama pasien. Ini
     * disengaja/wajar: begitu dokter memindah pasien ke tanggal baru, masuk
     * akal jarak H-7/dst dihitung ulang dari titik pemindahan itu, bukan
     * dari riwayat pendaftaran yang sudah tidak relevan lagi.
     *
     * Checkpoint dihitung dinamis tiap run (bukan dijadwalkan di muka)
     * dari SISA hari ke kunjungan HARI INI: kalau itu kelipatan 7 (7, 14,
     * 21, ...) dan lebih kecil dari reminder_h7_last_multiple_sent
     * (atau belum pernah terkirim sama sekali), kirim & catat kelipatan
     * itu. "Lebih kecil dari yang terakhir terkirim" (bukan "belum
     * pernah terkirim persis kelipatan ini") sengaja dipakai supaya kalau
     * command ini sempat berhenti beberapa hari dan melewati satu atau
     * lebih checkpoint, hanya checkpoint TERBARU yang masih relevan yang
     * dikirim satu kali - tidak membanjiri pasien dengan reminder susulan
     * untuk checkpoint yang sudah lewat.
     *
     * @return array{0: int, 1: int} [terkirim, kandidat]
     */
    protected function dispatchWeeklyReminders($connection, string $namaLike): array
    {
        $sent = 0;
        $candidates = 0;

        $connection->transaction(function () use ($connection, &$sent, &$candidates, $namaLike) {
            $rows = $connection->select(<<<'SQL'
                select *,
                  (tanggal_kunjungan - (now() at time zone 'Asia/Jakarta')::date) as days_until,
                  (tanggal_kunjungan - (created_at at time zone 'Asia/Jakarta')::date) as gap_days
                from kunjungan_reminder
                where status_kunjungan = 'active'
                  and nama_pasien ilike ?
                  and tanggal_kunjungan > (now() at time zone 'Asia/Jakarta')::date
                  and (tanggal_kunjungan - (created_at at time zone 'Asia/Jakarta')::date) > 7
                  and (tanggal_kunjungan - (now() at time zone 'Asia/Jakarta')::date) >= 7
                  and (tanggal_kunjungan - (now() at time zone 'Asia/Jakarta')::date) % 7 = 0
                  and (
                    reminder_h7_last_multiple_sent is null
                    or reminder_h7_last_multiple_sent > (tanggal_kunjungan - (now() at time zone 'Asia/Jakarta')::date)
                  )
                order by tanggal_kunjungan
                for update skip locked
                SQL,
                [$namaLike]
            );

            $candidates = count($rows);

            foreach ($rows as $row) {
                $multiple = (int) $row->days_until;

                if (! $row->nohp) {
                    $connection->table('reminder_log')->insert([
                        'no_rawat' => $row->no_rawat,
                        'jenis_reminder' => "h7_{$multiple}",
                        'status' => 'failed',
                        'error_message' => 'Nomor HP kosong/tidak valid',
                    ]);

                    continue;
                }

                [$ok, $responseBody] = $this->sendWaha("{$row->nohp}@c.us", $this->buildWeeklyMessage($multiple, $row));

                if ($ok) {
                    $connection->table('kunjungan_reminder')->where('no_rawat', $row->no_rawat)->update([
                        'reminder_h7_last_multiple_sent' => $multiple,
                        'reminder_h7_last_sent_at' => now(),
                    ]);
                }

                $connection->table('reminder_log')->insert([
                    'no_rawat' => $row->no_rawat,
                    'jenis_reminder' => "h7_{$multiple}",
                    'status' => $ok ? 'success' : 'failed',
                    'gateway_response' => json_encode($responseBody),
                    'error_message' => $ok ? null : json_encode($responseBody),
                ]);

                if ($ok) {
                    $sent++;
                }
            }
        });

        return [$sent, $candidates];
    }

    protected function buildMessage(string $kind, string $label, object $row): string
    {
        $jam = substr((string) $row->jam_kunjungan, 0, 5);
        $poli = $row->poli ?: 'poliklinik terkait';
        $dokter = $row->dokter ?: 'dokter terkait';

        // bookings.jenis_layanan (Periksa Sakit/Konsultasi Gizi/dst) tidak
        // ikut disimpan di kunjungan_reminder sendiri, jadi dicari dari
        // bookings kita lewat no_rawat yang sama. Kalau kunjungan ini bukan
        // hasil booking lewat bot (mis. jalur lain di GTK), tidak ketemu -
        // klausa "untuk ..." cukup dilewati, bukan menampilkan nilai
        // kosong/null.
        $jenisLayanan = $this->jenisLayananLabel($row->no_rawat);
        $untukLayanan = $jenisLayanan ? " untuk {$jenisLayanan}" : '';

        return "Selamat {$this->sapaanWaktu()},\n"
            .'Kami dari *Graha Tumbuh Kembang Anak Jombang* ingin mengingatkan jadwal kunjungan '
            ."*{$row->nama_pasien}*{$untukLayanan} di {$poli} bersama *{$dokter}* yang akan berlangsung "
            ."{$label} pukul *{$jam}* WIB.\n\n"
            .'Mohon bantuannya untuk mengonfirmasi kehadiran ya. Terima kasih banyak! 😊🙏';
    }

    /**
     * Pesan reminder mingguan (H-7/H-14/H-21/dst) - beda nada dari
     * buildMessage(): kunjungannya masih $multiple hari lagi (BUKAN
     * "besok"/"hari ini"), jadi WAJIB menyebut tanggal & sisa hari secara
     * eksplisit supaya pasien tidak salah kira kunjungannya sudah dekat.
     */
    protected function buildWeeklyMessage(int $multiple, object $row): string
    {
        $jam = substr((string) $row->jam_kunjungan, 0, 5);
        $poli = $row->poli ?: 'poliklinik terkait';
        $dokter = $row->dokter ?: 'dokter terkait';
        $tanggal = Carbon::parse($row->tanggal_kunjungan)->locale('id')->isoFormat('dddd, D MMMM YYYY');

        $jenisLayanan = $this->jenisLayananLabel($row->no_rawat);
        $untukLayanan = $jenisLayanan ? " untuk {$jenisLayanan}" : '';

        return "Selamat {$this->sapaanWaktu()},\n"
            .'Kami dari *Graha Tumbuh Kembang Anak Jombang* ingin mengingatkan jadwal kunjungan '
            ."*{$row->nama_pasien}*{$untukLayanan} di {$poli} bersama *{$dokter}* pada "
            ."*{$tanggal}* pukul *{$jam}* WIB (kurang lebih {$multiple} hari lagi).\n\n"
            .'Mohon dicatat ya, kami akan mengingatkan kembali menjelang hari-H. Terima kasih! 😊🙏';
    }

    /**
     * Sapaan waktu (Pagi/Siang/Sore/Malam) dihitung dari jam KIRIM reminder
     * ini, BUKAN jam kunjungan yang diingatkan - reminder H-1 hari yang
     * dikirim pagi tetap "Selamat Pagi" walau kunjungannya sendiri sore.
     * Selalu pakai Asia/Jakarta eksplisit (sama seperti seluruh query window
     * H-1/H-3/H-1 di atas), TIDAK bergantung timezone default aplikasi.
     */
    protected function sapaanWaktu(): string
    {
        $jam = (int) now('Asia/Jakarta')->format('G');

        return match (true) {
            $jam >= 3 && $jam < 11 => 'Pagi',
            $jam >= 11 && $jam < 15 => 'Siang',
            $jam >= 15 && $jam < 19 => 'Sore',
            default => 'Malam',
        };
    }

    protected function jenisLayananLabel(string $noRawat): ?string
    {
        $value = DB::connection('pgsql')->table('bookings')->where('no_rawat', $noRawat)->value('jenis_layanan');

        return $value ? JenisLayanan::tryFrom($value)?->label() : null;
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    protected function sendWaha(string $chatId, string $text): array
    {
        $response = Http::baseUrl(config('services.waha.url'))
            ->withHeaders(array_filter([
                'X-Api-Key' => config('services.waha.api_key'),
            ]))
            ->post('/api/sendText', [
                'session' => config('services.waha.session'),
                'chatId' => $chatId,
                'text' => $text,
            ]);

        return [$response->successful(), $response->json() ?? $response->body()];
    }
}
