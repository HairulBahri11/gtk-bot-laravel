<?php

namespace App\Console\Commands;

use App\Services\Quota\QuotaService;
use Illuminate\Console\Command;

/**
 * "Kuota background" (§3.1 langkah 4 PRD): refresh cache lokal poliklinik,
 * dokter aktif, jadwal, dan snapshot kuota per shift dari API GTK.
 */
class SyncQuota extends Command
{
    protected $signature = 'gtk:sync-quota {--days=14 : Jumlah hari ke depan yang disinkronkan}';

    protected $description = 'Sinkronkan poliklinik, dokter, jadwal, dan kuota shift dari API GTK ke cache lokal';

    public function handle(QuotaService $quota): int
    {
        $this->info('Menyinkronkan kuota dari API GTK...');

        $quota->syncFromGtk((int) $this->option('days'));

        $this->info('Sinkronisasi kuota selesai.');

        return self::SUCCESS;
    }
}
