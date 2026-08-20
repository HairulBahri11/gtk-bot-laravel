<?php

namespace App\Console\Commands;

use App\Services\Quota\QuotaService;
use Illuminate\Console\Command;

/**
 * "Kuota background" (§3.1 langkah 4 PRD): refresh cache lokal dokter aktif
 * dari API GTK, lalu bangun ulang snapshot kuota harian (quota_shifts) dari
 * jadwal dashboard (doctor_schedules, source='manual').
 *
 * TIDAK LAGI menyinkronkan jadwal (doctor_schedules) dari GTK - jadwal
 * dokter sekarang sepenuhnya dikelola manual dari dashboard Jadwal Dokter,
 * lihat QuotaService::syncFromGtk() untuk penjelasan lengkap.
 *
 * TIDAK LAGI menyinkronkan poliklinik dari GTK sama sekali (dihapus, bukan
 * cuma dinonaktifkan - lihat QuotaService::syncFromGtk()) - poliklinik
 * sekarang murni dikelola dari dashboard (menu Poliklinik).
 *
 * TIDAK LAGI DIJADWALKAN OTOMATIS (lihat routes/console.php) - jadwal dan
 * poliklinik sekarang sepenuhnya dikelola manual dari dashboard, tidak
 * perlu sinkronisasi berkala ke GTK sama sekali. Command ini tetap ada
 * untuk dijalankan MANUAL kalau suatu saat perlu refresh status aktif
 * dokter dari GTK. Untuk pembangunan ulang snapshot kuota harian yang
 * tetap wajib berjalan otomatis, lihat `quota:rebuild-shifts`
 * (RebuildQuotaShifts.php) - TIDAK memanggil GTK sama sekali.
 */
class SyncQuota extends Command
{
    protected $signature = 'gtk:sync-quota {--days=60 : Jumlah hari ke depan yang disinkronkan}';

    protected $description = '[Manual saja, tidak lagi terjadwal] Sinkronkan dokter dari API GTK, lalu bangun ulang snapshot kuota harian dari jadwal dashboard';

    public function handle(QuotaService $quota): int
    {
        $this->info('Menyinkronkan dokter dari API GTK & membangun ulang snapshot kuota...');

        $quota->syncFromGtk((int) $this->option('days'));

        $this->info('Sinkronisasi selesai.');

        return self::SUCCESS;
    }
}
