<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Jobs\SyncQuotaFromGtk;
use App\Models\Booking;
use App\Models\Doctor;
use App\Models\QuotaShift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
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
        $dokter = $request->input('dokter');

        $quotaShifts = QuotaShift::query()
            ->with(['doctor', 'poliklinik'])
            ->when($user->isDokter(), fn ($q) => $q->where('kode_dokter', $user->kode_dokter))
            ->when($dokter, fn ($q) => $q->where('kode_dokter', $dokter))
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
                'kuota_konsultasi_gizi' => $q->kuota_konsultasi_gizi,
                'kuota_terpakai_konsultasi_gizi' => $q->kuota_terpakai_konsultasi_gizi,
                'kuota_tersisa_konsultasi_gizi' => $q->kuota_tersisa_konsultasi_gizi,
                'kuota_konsultasi_tumbuh_kembang' => $q->kuota_konsultasi_tumbuh_kembang,
                'kuota_terpakai_konsultasi_tumbuh_kembang' => $q->kuota_terpakai_konsultasi_tumbuh_kembang,
                'kuota_tersisa_konsultasi_tumbuh_kembang' => $q->kuota_tersisa_konsultasi_tumbuh_kembang,
                'kuota_pemeriksaan' => $q->kuota_pemeriksaan,
                'kuota_terpakai_pemeriksaan' => $q->kuota_terpakai_pemeriksaan,
                'kuota_tersisa_pemeriksaan' => $q->kuota_tersisa_pemeriksaan,
                'status' => $q->status,
                'delay_minutes' => $q->delay_minutes,
                'reason' => $q->reason,
                'last_synced_at' => $q->last_synced_at?->toDateTimeString(),
            ]);

        // Antrian kedatangan (no_antrean) - lihat AntreanService::confirmArrival()
        // untuk bagaimana nomor ini diisi (SATU urutan gabungan per dokter+
        // tanggal+shift, TIDAK dipisah per jenis_layanan). Hanya booking yang
        // SUDAH datang (status Arrived) & sudah kebagian nomor yang tampil di
        // sini - ini murni tampilan, aksi "Datang" tetap di halaman Antrean.
        $antrean = Booking::query()
            ->with(['patient', 'doctor', 'poliklinik'])
            ->when($user->isDokter(), fn ($q) => $q->where('kode_dokter', $user->kode_dokter))
            ->when($dokter, fn ($q) => $q->where('kode_dokter', $dokter))
            ->whereDate('tanggal_periksa', $date)
            ->where('status', BookingStatus::Arrived->value)
            ->whereNotNull('no_antrean')
            // no_antrean HANYA unik per dokter+shift (lihat AntreanService::
            // nextQueueNumber()) - kalau filter "Semua Dokter" aktif, wajar
            // ada beberapa dokter sama-sama punya #1. Urutan kedua by
            // kode_dokter supaya hasilnya stabil/deterministik antar reload
            // (bukan bergantung urutan baris DB yang tidak terjamin), bukan
            // untuk memberi arti khusus pada urutan dokternya.
            ->orderBy('no_antrean')
            ->orderBy('kode_dokter')
            ->get()
            ->map(fn (Booking $b) => [
                'id' => $b->id,
                'no_antrean' => $b->no_antrean,
                'nama_pasien' => $b->patient?->nama,
                'no_rm' => $b->no_rm,
                'poliklinik' => $b->poliklinik?->nama_poliklinik,
                'dokter' => $b->doctor?->nama_dokter,
                'kode_dokter' => $b->kode_dokter,
                'shift' => $b->shift->value,
                'jenis_layanan' => $b->jenis_layanan->label(),
            ]);

        $doctors = Doctor::query()
            ->where('is_active', true)
            ->orderBy('nama_dokter')
            ->get(['kode_dokter', 'nama_dokter']);

        return Inertia::render('Kuota/Index', [
            'quotaShifts' => $quotaShifts,
            'antrean' => $antrean,
            'doctors' => $doctors,
            'filters' => ['tanggal' => $date, 'dokter' => $dokter],
        ]);
    }

    public function sync(): RedirectResponse
    {
        SyncQuotaFromGtk::dispatch();

        return back()->with('success', 'Sinkronisasi kuota sedang diproses di background. Data akan diperbarui dalam beberapa saat.');
    }

    /**
     * Sesuaikan alokasi kuota konsultasi (Gizi atau Tumbuh Kembang, lihat
     * "kategori") untuk SATU snapshot harian (mis. kuota pemeriksaan sepi
     * hari ini, geser sebagian ke konsultasi) - lihat docblock migration
     * 2026_08_15_000002_split_kuota_konsultasi_gizi_tumbuh_kembang_quota_shifts
     * untuk kenapa perubahan ini aman dari resync gtk:sync-quota berikutnya
     * (kedua kolom alokasi ini sengaja dikecualikan dari QuotaService::
     * rebuildQuotaShifts()). Hanya menyentuh baris QuotaShift ini - TIDAK
     * mengubah template DoctorSchedule (itu tugas JadwalDokterController,
     * berlaku ke minggu berikutnya, bukan hari yang sudah berjalan).
     */
    public function updateKonsultasi(Request $request, QuotaShift $quotaShift): RedirectResponse
    {
        abort_if($request->user()->isDokter() && $quotaShift->kode_dokter !== $request->user()->kode_dokter, 403);

        $data = $request->validate([
            'kategori' => ['required', Rule::in(['gizi', 'tumbuh_kembang'])],
            // "max" (bukan "lte") - kuota_total di sini adalah nilai TETAP
            // hasil komputasi, bukan nama field lain di request; "lte:field"
            // Laravel selalu membandingkan ke field LAIN di input, bukan
            // literal angka.
            'kuota_konsultasi' => ['required', 'integer', 'min:0', 'max:'.$quotaShift->kuota_total],
        ]);

        $kolom = $data['kategori'] === 'gizi' ? 'kuota_konsultasi_gizi' : 'kuota_konsultasi_tumbuh_kembang';

        $quotaShift->update([$kolom => $data['kuota_konsultasi']]);

        return back()->with('success', 'Alokasi kuota konsultasi diperbarui.');
    }
}
