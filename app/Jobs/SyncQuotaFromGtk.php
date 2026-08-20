<?php

namespace App\Jobs;

use App\Services\Quota\QuotaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Jalankan QuotaService::syncFromGtk() di background - dipicu manual dari
 * tombol sync di halaman Kuota. Dijalankan sebagai job, bukan langsung di
 * request web, supaya tidak kena batas max_execution_time (60s) saat proses
 * sync melakukan banyak panggilan API GTK + query ke DB remote (Supabase).
 */
class SyncQuotaFromGtk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $timeout = 300;

    public function __construct(protected int $daysAhead = 60)
    {
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('gtk-sync-quota'))->dontRelease()];
    }

    public function handle(QuotaService $quota): void
    {
        $quota->syncFromGtk($this->daysAhead);
    }
}
