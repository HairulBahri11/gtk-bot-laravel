<?php

namespace App\Http\Controllers;

use App\Jobs\SyncQuotaFromGtk;
use App\Models\QuotaShift;
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
                'kuota_konsultasi' => $q->kuota_konsultasi,
                'kuota_terpakai_konsultasi' => $q->kuota_terpakai_konsultasi,
                'kuota_tersisa_konsultasi' => $q->kuota_tersisa_konsultasi,
                'kuota_pemeriksaan' => $q->kuota_pemeriksaan,
                'kuota_terpakai_pemeriksaan' => $q->kuota_terpakai_pemeriksaan,
                'kuota_tersisa_pemeriksaan' => $q->kuota_tersisa_pemeriksaan,
                'status' => $q->status,
                'delay_minutes' => $q->delay_minutes,
                'reason' => $q->reason,
                'last_synced_at' => $q->last_synced_at?->toDateTimeString(),
            ]);

        return Inertia::render('Kuota/Index', [
            'quotaShifts' => $quotaShifts,
            'filters' => ['tanggal' => $date],
        ]);
    }

    public function sync(): RedirectResponse
    {
        SyncQuotaFromGtk::dispatch();

        return back()->with('success', 'Sinkronisasi kuota sedang diproses di background. Data akan diperbarui dalam beberapa saat.');
    }

    /**
     * Sesuaikan alokasi kuota konsultasi untuk SATU snapshot harian (mis.
     * kuota pemeriksaan sepi hari ini, geser sebagian ke konsultasi) - lihat
     * docblock migration 2026_08_13_000002_add_kuota_konsultasi_to_quota_shifts_table
     * untuk kenapa perubahan ini aman dari resync gtk:sync-quota berikutnya
     * (kolom ini sengaja dikecualikan dari QuotaService::rebuildQuotaShifts()).
     * Hanya menyentuh baris QuotaShift ini - TIDAK mengubah template
     * DoctorSchedule (itu tugas JadwalDokterController, berlaku ke minggu
     * berikutnya, bukan hari yang sudah berjalan).
     */
    public function updateKonsultasi(Request $request, QuotaShift $quotaShift): RedirectResponse
    {
        abort_if($request->user()->isDokter() && $quotaShift->kode_dokter !== $request->user()->kode_dokter, 403);

        $data = $request->validate([
            // "max" (bukan "lte") - kuota_total di sini adalah nilai TETAP
            // hasil komputasi, bukan nama field lain di request; "lte:field"
            // Laravel selalu membandingkan ke field LAIN di input, bukan
            // literal angka.
            'kuota_konsultasi' => ['required', 'integer', 'min:0', 'max:'.$quotaShift->kuota_total],
        ]);

        $quotaShift->update(['kuota_konsultasi' => $data['kuota_konsultasi']]);

        return back()->with('success', 'Alokasi kuota konsultasi diperbarui.');
    }
}
