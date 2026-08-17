<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Antrean\AntreanService;
use App\Services\Gtk\GtkApiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Antrean": monitoring & aksi manual booking/waitlist/no-show.
 */
class AntreanController extends Controller
{
    /**
     * Enam status paling relevan untuk dipantau front-desk secara realtime
     * (label ramah untuk masing-masing) - "rescheduled" tetap bisa dilihat
     * lewat dropdown filter status, hanya tidak ditampilkan sebagai kartu
     * ringkasan karena jarang terjadi.
     */
    private const FUNNEL_STATUSES = [
        BookingStatus::Waitlist->value => 'Daftar Tunggu',
        BookingStatus::Booked->value => 'Belum Datang',
        BookingStatus::Confirmed->value => 'Dikonfirmasi H-30m',
        BookingStatus::Arrived->value => 'Sudah Datang',
        BookingStatus::Selesai->value => 'Selesai',
        BookingStatus::NoShow->value => 'No-Show',
        BookingStatus::Cancelled->value => 'Dibatalkan',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $status = $request->input('status');
        $search = trim((string) $request->input('search', ''));
        $tanggal = $request->input('tanggal') ?: Carbon::today()->toDateString();

        $scoped = fn () => Booking::query()
            ->when($user->isDokter(), fn ($q) => $q->where('kode_dokter', $user->kode_dokter))
            ->whereDate('tanggal_periksa', $tanggal);

        $statusCounts = $scoped()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $funnel = collect(self::FUNNEL_STATUSES)->map(
            fn (string $label, string $value) => [
                'status' => $value,
                'label' => $label,
                'count' => (int) ($statusCounts[$value] ?? 0),
            ],
        )->values();

        $bookings = $scoped()
            ->with(['patient', 'doctor', 'poliklinik', 'chatSession'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('no_rm', 'like', "%{$search}%")
                        ->orWhereHas('patient', function ($p) use ($search) {
                            $p->where('nama', 'like', "%{$search}%")
                                ->orWhere('no_hp', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByRaw("CASE shift WHEN 'pagi' THEN 1 WHEN 'sore' THEN 2 WHEN 'malam' THEN 3 ELSE 4 END")
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Booking $b) => [
                'id' => $b->id,
                'no_rm' => $b->no_rm,
                'nama_pasien' => $b->patient?->nama,
                'usia' => $b->patient?->tanggal_lahir ? $this->formatUsia($b->patient->tanggal_lahir) : null,
                'no_hp' => $b->patient?->no_hp,
                'no_rawat' => $b->no_rawat,
                'poliklinik' => $b->poliklinik?->nama_poliklinik,
                'dokter' => $b->doctor?->nama_dokter,
                'tanggal_periksa' => $b->tanggal_periksa->toDateString(),
                'shift' => $b->shift->value,
                'status' => $b->status->value,
                'waitlist_position' => $b->waitlist_position,
                'keluhan_tags' => $this->keluhanTags($b->chatSession?->context['keluhan'] ?? null),
                'chat_session_id' => $b->chat_session_id,
            ]);

        return Inertia::render('Antrean/Index', [
            'bookings' => $bookings,
            'filters' => ['status' => $status, 'search' => $search, 'tanggal' => $tanggal],
            'funnel' => $funnel,
        ]);
    }

    public function confirmArrival(Booking $booking, AntreanService $antrean): RedirectResponse
    {
        $antrean->confirmArrival($booking);

        return back()->with('success', 'Kedatangan pasien dikonfirmasi.');
    }

    public function complete(Booking $booking, AntreanService $antrean): RedirectResponse
    {
        $antrean->completeVisit($booking);

        return back()->with('success', 'Kunjungan pasien ditandai selesai.');
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

    private function formatUsia(Carbon $tanggalLahir): string
    {
        $diff = $tanggalLahir->diff(now());

        if ($diff->y > 0) {
            return $diff->m > 0 ? "{$diff->y} th {$diff->m} bln" : "{$diff->y} th";
        }

        return "{$diff->m} bln";
    }

    /**
     * Pecah teks keluhan bebas hasil ekstraksi AI (satu kalimat/frasa) jadi
     * beberapa tag pendek untuk ditampilkan sebagai chip - dipecah dari
     * pemisah alami yang benar-benar ada di teksnya (koma, kata hubung
     * "dan"), BUKAN NLP keyword extraction sungguhan. Kalau tidak ada
     * pemisah, kembalikan sebagai satu tag utuh apa adanya.
     *
     * @return array<int, string>
     */
    private function keluhanTags(?string $keluhan): array
    {
        if (blank($keluhan)) {
            return [];
        }

        $parts = preg_split('/\s*,\s*|\s+dan\s+/i', trim($keluhan));

        return collect($parts)->filter(fn ($p) => trim($p) !== '')->map(fn ($p) => trim($p))->values()->all();
    }
}
