<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Antrean\AntreanService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Manajemen No-Show (§3.2 & §6 PRD): tandai booking yang lewat jadwal &
 * belum "arrived" sebagai No-Show, lalu geser buffer antrean.
 */
class ProcessNoShows extends Command
{
    protected $signature = 'gtk:process-no-show {--grace=60 : Menit toleransi setelah shift berakhir}';

    protected $description = 'Deteksi & proses booking yang No-Show setelah shift berakhir';

    public function handle(AntreanService $antrean): int
    {
        $grace = (int) $this->option('grace');
        $now = now();
        $shiftWindows = config('gtk.shift_windows');

        $candidates = Booking::query()
            ->whereIn('status', [BookingStatus::Booked->value, BookingStatus::Confirmed->value])
            ->whereDate('tanggal_periksa', '<=', $now->toDateString())
            ->get();

        $count = 0;

        foreach ($candidates as $booking) {
            $window = $shiftWindows[$booking->shift->value] ?? null;

            if (! $window) {
                continue;
            }

            $shiftEndsAt = Carbon::parse($booking->tanggal_periksa->toDateString().' '.$window['end'])
                ->addMinutes($grace);

            if ($now->lt($shiftEndsAt)) {
                continue;
            }

            $antrean->markNoShow($booking);
            $count++;
        }

        $this->info("No-show diproses: {$count}");

        return self::SUCCESS;
    }
}
