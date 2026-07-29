<?php

namespace App\Console\Commands;

use App\Services\Gtk\GtkApiException;
use App\Services\Gtk\GtkApiService;
use App\Support\IndonesianPhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sync data kunjungan dari GTK API (GET /reminderkunjungan) ke tabel
 * kunjungan_reminder di Supabase, dibaca oleh Edge Function
 * dispatch-reminders (supabase/functions/dispatch-reminders) untuk kirim
 * reminder WA H-1 hari/3 jam/1 jam. HANYA sync - command ini TIDAK PERNAH
 * mengirim WA sama sekali, itu murni tugas dispatch-reminders.
 *
 * Dijalankan sebagai command Laravel (bukan Supabase Edge Function) karena
 * GTK API hanya bisa diakses lewat ZeroTier VPN yang sudah di-join mesin
 * ini - runtime Edge Function Supabase tidak bisa ikut di-join-kan ke VPN
 * itu. Selalu tulis eksplisit ke koneksi 'pgsql' (Supabase), berapa pun
 * DB_CONNECTION default Laravel saat ini (mis. mysql lokal saat dev).
 */
class SyncKunjunganReminder extends Command
{
    protected $signature = 'gtk:sync-kunjungan-reminder';

    protected $description = 'Sinkronkan data kunjungan H & H+1 dari GTK API ke tabel kunjungan_reminder (Supabase)';

    public function handle(GtkApiService $gtk): int
    {
        $dates = collect([now()->toDateString(), now()->copy()->addDay()->toDateString()])->unique();
        $connection = DB::connection('pgsql');

        $upserted = 0;
        $cancelled = 0;

        foreach ($dates as $tanggal) {
            try {
                $result = $gtk->reminderKunjungan(['tanggal' => $tanggal]);
            } catch (GtkApiException $e) {
                $this->warn("Gagal ambil reminderkunjungan tanggal {$tanggal}: {$e->getMessage()}");

                continue;
            }

            $list = $result['list'] ?? [];
            $seenNoRawat = [];

            foreach ($list as $item) {
                $noRawat = trim((string) ($item['no_rawat'] ?? ''));
                $jam = trim((string) ($item['jam'] ?? ''));

                if ($noRawat === '' || $jam === '') {
                    continue;
                }

                $seenNoRawat[] = $noRawat;

                // Field "nohp" dari GTK API sering berisi placeholder ("-",
                // "--", "0") utk transaksi non-konsultasi (mis. penjualan
                // bebas/vaksin harian di Apotik) - toInternational() akan
                // menolaknya (null) sehingga otomatis di-skip oleh dispatcher,
                // bukan dikirim ke nomor sampah.
                $nohpRaw = $item['nohp'] ?? null;
                $nohp = IndonesianPhoneNumber::toInternational($nohpRaw);

                $existing = $connection->table('kunjungan_reminder')
                    ->where('no_rawat', $noRawat)
                    ->first(['tanggal_kunjungan', 'jam_kunjungan']);

                // Reschedule terdeteksi & reminder itu SUDAH 'sent' sebelumnya
                // -> reset ke 'pending' supaya terkirim ulang dgn jadwal yang
                // benar. Yang masih 'pending' otomatis re-evaluate sendiri
                // pakai jadwal_at baru, tidak perlu disentuh.
                $changed = $existing
                    && ((string) $existing->tanggal_kunjungan !== $tanggal
                        || substr((string) $existing->jam_kunjungan, 0, 5) !== substr($jam, 0, 5));

                $connection->statement(
                    <<<'SQL'
                    insert into kunjungan_reminder (
                        no_rawat, nama_pasien, nohp_raw, nohp, kodepoli, poli, dokter,
                        tanggal_kunjungan, jam_kunjungan, status_kunjungan, last_synced_at
                    ) values (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', now())
                    on conflict (no_rawat) do update set
                        nama_pasien = excluded.nama_pasien,
                        nohp_raw = excluded.nohp_raw,
                        nohp = excluded.nohp,
                        kodepoli = excluded.kodepoli,
                        poli = excluded.poli,
                        dokter = excluded.dokter,
                        tanggal_kunjungan = excluded.tanggal_kunjungan,
                        jam_kunjungan = excluded.jam_kunjungan,
                        status_kunjungan = 'active',
                        last_synced_at = now(),
                        reminder_h1hari_status = case
                            when ? and kunjungan_reminder.reminder_h1hari_status = 'sent' then 'pending'
                            else kunjungan_reminder.reminder_h1hari_status end,
                        reminder_h3jam_status = case
                            when ? and kunjungan_reminder.reminder_h3jam_status = 'sent' then 'pending'
                            else kunjungan_reminder.reminder_h3jam_status end,
                        reminder_h1jam_status = case
                            when ? and kunjungan_reminder.reminder_h1jam_status = 'sent' then 'pending'
                            else kunjungan_reminder.reminder_h1jam_status end
                    SQL,
                    [
                        $noRawat, (string) ($item['nama_pasien'] ?? '-'), $nohpRaw, $nohp,
                        $item['kodepoli'] ?? null, $item['poli'] ?? null, $item['dokter'] ?? null,
                        $tanggal, $jam,
                        $changed, $changed, $changed,
                    ]
                );

                $upserted++;
            }

            if ($seenNoRawat === []) {
                // Jangan tandai batal apa pun kalau list kosong - bisa jadi
                // respons API sedang bermasalah/sementara, bukan berarti
                // semua kunjungan hari itu benar-benar batal.
                $this->warn("Tidak ada kunjungan dari GTK utk tanggal {$tanggal} - lewati deteksi batal.");

                continue;
            }

            $cancelledThisDate = $connection->table('kunjungan_reminder')
                ->where('tanggal_kunjungan', $tanggal)
                ->where('status_kunjungan', 'active')
                ->whereNotIn('no_rawat', $seenNoRawat)
                ->update(['status_kunjungan' => 'cancelled', 'last_synced_at' => now()]);

            $cancelled += $cancelledThisDate;
        }

        $this->info("Sync selesai: {$upserted} kunjungan di-upsert, {$cancelled} ditandai batal.");

        return self::SUCCESS;
    }
}
