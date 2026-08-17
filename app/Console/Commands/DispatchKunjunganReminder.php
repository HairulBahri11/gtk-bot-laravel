<?php

namespace App\Console\Commands;

use App\Enums\JenisLayanan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Evaluasi window H-1 hari / H-3 jam / H-1 jam utk tiap kunjungan aktif di
 * kunjungan_reminder (Supabase, diisi oleh SyncKunjunganReminder), kirim WA
 * lewat WAHA, lalu update status + tulis audit ke reminder_log. Idempotent
 * (dijaga kolom reminder_*_status per kunjungan+jenis), proses baca+kunci
 * baris dibungkus 1 transaksi (FOR UPDATE SKIP LOCKED) supaya aman kalau
 * invocation sebelumnya belum selesai saat yang berikutnya mulai.
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

    protected $description = 'Evaluasi window reminder H-1 hari/3 jam/1 jam & kirim WA (tabel kunjungan_reminder di Supabase)';

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

        $this->info("Dispatch selesai: {$candidates} kandidat, {$sent} terkirim, {$skipped} di-skip, {$expired} expired.");

        return self::SUCCESS;
    }

    protected function buildMessage(string $kind, string $label, object $row): string
    {
        $jam = substr((string) $row->jam_kunjungan, 0, 5);
        $poli = $row->poli ?: 'poliklinik terkait';
        $dokter = $row->dokter ?: 'dokter terkait';

        // kunjungan_reminder disinkronkan dari GTK reminderkunjungan (lihat
        // SyncKunjunganReminder) - API itu TIDAK mengembalikan kategori
        // layanan (Periksa Sakit/Konsultasi Gizi/dst) sama sekali, jadi
        // dicari dari bookings kita sendiri lewat no_rawat yang sama. Kalau
        // kunjungan ini bukan hasil booking lewat bot (mis. jalur lain di
        // GTK), tidak ketemu - klausa "untuk ..." cukup dilewati, bukan
        // menampilkan nilai kosong/null.
        $jenisLayanan = $this->jenisLayananLabel($row->no_rawat);
        $untukLayanan = $jenisLayanan ? " untuk {$jenisLayanan}" : '';

        return "Selamat {$this->sapaanWaktu()},\n"
            .'Kami dari Graha Tumbuh Kembang Anak Jombang ingin mengingatkan jadwal kunjungan '
            ."{$row->nama_pasien}{$untukLayanan} di {$poli} bersama {$dokter} yang akan berlangsung "
            ."{$label} pukul {$jam} WIB.\n\n"
            .'Mohon bantuannya untuk mengonfirmasi kehadiran ya. Terima kasih banyak! 😊🙏';
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
