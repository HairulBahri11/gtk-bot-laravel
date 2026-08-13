<?php

namespace App\Console\Commands;

use App\Services\Quota\QuotaService;
use Illuminate\Console\Command;

/**
 * "Kuota background" (§3.1 langkah 4 PRD): refresh cache lokal poliklinik &
 * dokter aktif dari API GTK, lalu bangun ulang snapshot kuota harian
 * (quota_shifts) dari jadwal dashboard (doctor_schedules, source='manual').
 *
 * TIDAK LAGI menyinkronkan jadwal (doctor_schedules) dari GTK - jadwal
 * dokter sekarang sepenuhnya dikelola manual dari dashboard Jadwal Dokter,
 * lihat QuotaService::syncFromGtk() untuk penjelasan lengkap.
 *
 * TIDAK LAGI DIJADWALKAN OTOMATIS (lihat routes/console.php) - jadwal,
 * kuota, poliklinik, dan dokter sekarang sepenuhnya dikelola manual dari
 * dashboard, tidak perlu sinkronisasi berkala ke GTK sama sekali. Command
 * ini tetap ada untuk dijalankan MANUAL kalau suatu saat perlu refresh
 * status aktif dokter/poliklinik dari GTK. Untuk pembangunan ulang snapshot
 * kuota harian yang tetap wajib berjalan otomatis, lihat
 * `quota:rebuild-shifts` (RebuildQuotaShifts.php) - TIDAK memanggil GTK
 * sama sekali.
 */
class SyncQuota extends Command
{
    protected $signature = 'gtk:sync-quota {--days=14 : Jumlah hari ke depan yang disinkronkan}';

    protected $description = '[Manual saja, tidak lagi terjadwal] Sinkronkan poliklinik & dokter dari API GTK, lalu bangun ulang snapshot kuota harian dari jadwal dashboard';

    public function handle(QuotaService $quota): int
    {
        $this->info('Menyinkronkan poliklinik/dokter dari API GTK & membangun ulang snapshot kuota...');

        $quota->syncFromGtk((int) $this->option('days'));

        $this->info('Sinkronisasi selesai.');

        return self::SUCCESS;
    }
}
