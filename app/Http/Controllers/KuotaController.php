<?php

namespace App\Http\Controllers;

use App\Models\QuotaShift;
use App\Services\Quota\QuotaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Kuota background": halaman monitoring kuota per dokter/poli/shift.
 */
class KuotaController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $date = $request->input('tanggal', Carbon::today()->toDateString());

        $quotaShifts = QuotaShift::query()
            ->with(['doctor', 'poliklinik'])
            ->when($user->isDokter(), fn ($q) => $q->where('kode_dokter', $user->kode_dokter))
            ->whereDate('tanggal', $date)
            ->orderBy('kode_poliklinik')
            ->orderByRaw("CASE shift WHEN 'pagi' THEN 1 WHEN 'sore' THEN 2 WHEN 'malam' THEN 3 ELSE 4 END")
            ->get()
            ->map(fn (QuotaShift $q) => [
                'id' => $q->id,
                'tanggal' => $q->tanggal->toDateString(),
                'shift' => $q->shift->value,
                'kode_dokter' => $q->kode_dokter,
                'nama_dokter' => $q->doctor?->nama_dokter,
                'kode_poliklinik' => $q->kode_poliklinik,
                'nama_poliklinik' => $q->poliklinik?->nama_poliklinik,
                'kuota_total' => $q->kuota_total,
                'kuota_terpakai' => $q->kuota_terpakai,
                'kuota_tersisa' => $q->kuota_tersisa,
                'last_synced_at' => $q->last_synced_at?->toDateTimeString(),
            ]);

        return Inertia::render('Kuota/Index', [
            'quotaShifts' => $quotaShifts,
            'filters' => ['tanggal' => $date],
        ]);
    }

    public function sync(QuotaService $quota): RedirectResponse
    {
        $quota->syncFromGtk();

        return back()->with('success', 'Sinkronisasi kuota selesai.');
    }
}
