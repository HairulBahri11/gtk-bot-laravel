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
 */
class SyncQuota extends Command
{
    protected $signature = 'gtk:sync-quota {--days=14 : Jumlah hari ke depan yang disinkronkan}';

    protected $description = 'Sinkronkan poliklinik & dokter dari API GTK, lalu bangun ulang snapshot kuota harian dari jadwal dashboard';

    public function handle(QuotaService $quota): int
    {
        $this->info('Menyinkronkan poliklinik/dokter dari API GTK & membangun ulang snapshot kuota...');

        $quota->syncFromGtk((int) $this->option('days'));

        $this->info('Sinkronisasi selesai.');

        return self::SUCCESS;
    }
}
