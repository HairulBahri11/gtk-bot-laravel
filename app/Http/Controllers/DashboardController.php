<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\QuotaShift;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $today = Carbon::today()->toDateString();

        $bookingsToday = Booking::query()
            ->when($user->isDokter(), fn ($q) => $q->where('kode_dokter', $user->kode_dokter))
            ->whereDate('tanggal_periksa', $today)
            ->count();

        $activeQueue = Booking::query()
            ->when($user->isDokter(), fn ($q) => $q->where('kode_dokter', $user->kode_dokter))
            ->whereIn('status', [BookingStatus::Booked->value, BookingStatus::Confirmed->value, BookingStatus::Waitlist->value])
            ->count();

        $quotaToday = QuotaShift::query()
            ->when($user->isDokter(), fn ($q) => $q->where('kode_dokter', $user->kode_dokter))
            ->whereDate('tanggal', $today)
            ->get();

        $kuotaTersisaHariIni = $quotaToday->sum(fn (QuotaShift $q) => $q->kuota_tersisa);

        $last30Days = Carbon::today()->subDays(30)->toDateString();
        $totalSelesai = Booking::query()
            ->when($user->isDokter(), fn ($q) => $q->where('kode_dokter', $user->kode_dokter))
            ->where('tanggal_periksa', '>=', $last30Days)
            ->whereIn('status', [BookingStatus::Arrived->value, BookingStatus::NoShow->value])
            ->count();

        $totalNoShow = Booking::query()
            ->when($user->isDokter(), fn ($q) => $q->where('kode_dokter', $user->kode_dokter))
            ->where('tanggal_periksa', '>=', $last30Days)
            ->where('status', BookingStatus::NoShow->value)
            ->count();

        $shiftToday = $quotaToday
            ->groupBy(fn (QuotaShift $q) => $q->shift->value)
            ->map(fn ($rows, $shift) => [
                'shift' => $shift,
                'total' => $rows->sum('kuota_total'),
                'used' => $rows->sum('kuota_terpakai'),
            ])
            ->values();

        $hariSingkat = [
            'Monday' => 'Sen', 'Tuesday' => 'Sel', 'Wednesday' => 'Rab', 'Thursday' => 'Kam',
            'Friday' => 'Jum', 'Saturday' => 'Sab', 'Sunday' => 'Min',
        ];

        $weeklyTrend = collect(range(6, 0))->map(function (int $daysAgo) use ($user, $hariSingkat) {
            $date = Carbon::today()->subDays($daysAgo);

            $count = Booking::query()
                ->when($user->isDokter(), fn ($q) => $q->where('kode_dokter', $user->kode_dokter))
                ->whereDate('tanggal_periksa', $date)
                ->count();

            return [
                'date' => $date->toDateString(),
                'label' => $hariSingkat[$date->format('l')],
                'count' => $count,
            ];
        })->values();

        return Inertia::render('Dashboard/Index', [
            'stats' => [
                'bookings_today' => $bookingsToday,
                'active_queue' => $activeQueue,
                'kuota_tersisa_hari_ini' => $kuotaTersisaHariIni,
                'no_show_rate' => $totalSelesai > 0 ? round(($totalNoShow / $totalSelesai) * 100, 1) : 0,
            ],
            'shiftToday' => $shiftToday,
            'weeklyTrend' => $weeklyTrend,
        ]);
    }
}
