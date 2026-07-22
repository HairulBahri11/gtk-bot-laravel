<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\Antrean\AntreanService;
use App\Services\Gtk\GtkApiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Antrean": monitoring & aksi manual booking/waitlist/no-show.
 */
class AntreanController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $status = $request->input('status');

        $bookings = Booking::query()
            ->with(['patient', 'doctor', 'poliklinik'])
            ->when($user->isDokter(), fn ($q) => $q->where('kode_dokter', $user->kode_dokter))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('tanggal_periksa')
            ->orderByRaw("CASE shift WHEN 'pagi' THEN 1 WHEN 'sore' THEN 2 WHEN 'malam' THEN 3 ELSE 4 END")
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Booking $b) => [
                'id' => $b->id,
                'no_rm' => $b->no_rm,
                'nama_pasien' => $b->patient?->nama,
                'no_rawat' => $b->no_rawat,
                'poliklinik' => $b->poliklinik?->nama_poliklinik,
                'dokter' => $b->doctor?->nama_dokter,
                'tanggal_periksa' => $b->tanggal_periksa->toDateString(),
                'shift' => $b->shift->value,
                'status' => $b->status->value,
                'waitlist_position' => $b->waitlist_position,
                'buffer_shifted_count' => $b->buffer_shifted_count,
            ]);

        return Inertia::render('Antrean/Index', [
            'bookings' => $bookings,
            'filters' => ['status' => $status],
        ]);
    }

    public function confirmArrival(Booking $booking, AntreanService $antrean): RedirectResponse
    {
        $antrean->confirmArrival($booking);

        return back()->with('success', 'Kedatangan pasien dikonfirmasi.');
    }

    public function noShow(Booking $booking, AntreanService $antrean): RedirectResponse
    {
        try {
            $antrean->markNoShow($booking);
        } catch (GtkApiException $e) {
            return back()->with('error', 'Gagal memproses No-Show: '.$e->getMessage());
        }

        return back()->with('success', 'Booking ditandai No-Show, buffer antrean digeser.');
    }

    public function cancel(Request $request, Booking $booking, AntreanService $antrean): RedirectResponse
    {
        try {
            $antrean->cancelBooking($booking, $request->input('reason', 'Dibatalkan oleh admin'));
        } catch (GtkApiException $e) {
            return back()->with('error', 'Gagal membatalkan: '.$e->getMessage());
        }

        return back()->with('success', 'Booking dibatalkan.');
    }
}
