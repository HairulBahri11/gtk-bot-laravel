<?php

namespace App\Http\Controllers;

use App\Enums\Shift;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Poliklinik;
use App\Models\QuotaShift;
use App\Models\User;
use App\Services\Antrean\AntreanService;
use App\Services\Quota\QuotaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Jadwal Dokter": kelola jadwal mingguan (source='manual') + status harian
 * per shift (buka/dibatalkan/delay). Dashboard ini SATU-SATUNYA sumber
 * jadwal yang dipakai untuk booking - gtk:sync-quota tidak lagi
 * menyinkronkan jadwal dari GTK sama sekali (lihat QuotaService::
 * syncFromGtk()), baris source='gtk' lama (kalau masih ada dari sebelum
 * perubahan ini) sengaja diabaikan di seluruh query booking & tidak pernah
 * bisa diedit dari sini.
 */
class JadwalDokterController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $tanggal = $request->input('tanggal', Carbon::today()->toDateString());

        $schedules = DoctorSchedule::query()
            ->with(['doctor', 'poliklinik'])
            ->where('source', 'manual')
            ->when($user->isDokter(), fn ($q) => $q->where('kode_dokter', $user->kode_dokter))
            ->orderByRaw("CASE hari WHEN 'SENIN' THEN 1 WHEN 'SELASA' THEN 2 WHEN 'RABU' THEN 3 WHEN 'KAMIS' THEN 4 WHEN 'JUMAT' THEN 5 WHEN 'SABTU' THEN 6 ELSE 7 END")
            ->orderBy('jam_mulai')
            ->get();

        $kodeDokterList = $schedules->pluck('kode_dokter')->unique()->values();

        // Master dokter buat dropdown "Kode Dokter" di form tambah jadwal -
        // dokter cuma boleh pilih dirinya sendiri, admin boleh pilih semua
        // dokter aktif. Poliklinik dokter TIDAK dipakai otomatis di sini
        // (bisa kosong kalau sync GTK belum/tidak mengembalikannya) - admin
        // pilih poliklinik sendiri lewat dropdown terpisah di form, jadwal &
        // kuota dikelola manual dari dashboard, tidak bergantung data GTK.
        $doctors = Doctor::query()
            ->where('is_active', true)
            ->when($user->isDokter(), fn ($q) => $q->where('kode_dokter', $user->kode_dokter))
            ->orderBy('nama_dokter')
            ->get(['kode_dokter', 'nama_dokter']);

        $poliklinik = Poliklinik::query()
            ->where('is_active', true)
            ->orderBy('nama_poliklinik')
            ->get(['kode_poliklinik', 'nama_poliklinik']);

        $statuses = QuotaShift::query()
            ->whereIn('kode_dokter', $kodeDokterList)
            ->whereDate('tanggal', $tanggal)
            ->get()
            ->map(fn (QuotaShift $q) => [
                'kode_dokter' => $q->kode_dokter,
                'shift' => $q->shift->value,
                'status' => $q->status,
                'delay_minutes' => $q->delay_minutes,
                'reason' => $q->reason,
                // Kuota TERSEDIA (bukan alokasi) untuk tanggal ini secara
                // spesifik - baru bisa dihitung dari snapshot harian
                // (quota_shifts), bukan dari template mingguan
                // (doctor_schedules) yang ditampilkan di tabel bawah.
                'kuota_total' => $q->kuota_total,
                'kuota_terpakai' => $q->kuota_terpakai,
                'kuota_tersisa' => $q->kuota_tersisa,
                'kuota_pemeriksaan' => $q->kuota_pemeriksaan,
                'kuota_terpakai_pemeriksaan' => $q->kuota_terpakai_pemeriksaan,
                'kuota_tersisa_pemeriksaan' => $q->kuota_tersisa_pemeriksaan,
                'kuota_konsultasi_gizi' => $q->kuota_konsultasi_gizi,
                'kuota_terpakai_konsultasi_gizi' => $q->kuota_terpakai_konsultasi_gizi,
                'kuota_tersisa_konsultasi_gizi' => $q->kuota_tersisa_konsultasi_gizi,
                'kuota_konsultasi_tumbuh_kembang' => $q->kuota_konsultasi_tumbuh_kembang,
                'kuota_terpakai_konsultasi_tumbuh_kembang' => $q->kuota_terpakai_konsultasi_tumbuh_kembang,
                'kuota_tersisa_konsultasi_tumbuh_kembang' => $q->kuota_tersisa_konsultasi_tumbuh_kembang,
            ])
            ->values();

        return Inertia::render('Jadwal/Index', [
            'schedules' => $schedules->map(fn (DoctorSchedule $s) => [
                'id' => $s->id,
                'kode_dokter' => $s->kode_dokter,
                'nama_dokter' => $s->doctor?->nama_dokter,
                'kode_poliklinik' => $s->kode_poliklinik,
                'nama_poliklinik' => $s->poliklinik?->nama_poliklinik,
                'hari' => $s->hari,
                'jam_mulai' => substr($s->jam_mulai, 0, 5),
                'jam_selesai' => substr($s->jam_selesai, 0, 5),
                'shift' => $s->shift->value,
                'kuota_total' => $s->kuota_total,
                'kuota_konsultasi_gizi' => $s->kuota_konsultasi_gizi,
                'kuota_konsultasi_tumbuh_kembang' => $s->kuota_konsultasi_tumbuh_kembang,
                'kuota_pemeriksaan' => $s->kuota_pemeriksaan,
            ]),
            'statuses' => $statuses,
            'doctors' => $doctors->map(fn (Doctor $d) => [
                'kode_dokter' => $d->kode_dokter,
                'nama_dokter' => $d->nama_dokter,
            ]),
            'poliklinik' => $poliklinik->map(fn (Poliklinik $p) => [
                'kode_poliklinik' => $p->kode_poliklinik,
                'nama_poliklinik' => $p->nama_poliklinik,
            ]),
            'filters' => ['tanggal' => $tanggal],
            'isDokter' => $user->isDokter(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kode_dokter' => ['required', 'string', Rule::exists('doctors', 'kode_dokter')],
            'kode_poliklinik' => ['required', 'string', Rule::exists('poliklinik', 'kode_poliklinik')],
            'hari' => ['required', Rule::in(['SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT', 'SABTU', 'MINGGU'])],
            'jam_mulai' => ['required', 'date_format:H:i'],
            'jam_selesai' => ['required', 'date_format:H:i', 'after:jam_mulai'],
            'kuota_total' => ['required', 'integer', 'min:0'],
            'kuota_konsultasi_gizi' => ['required', 'integer', 'min:0'],
            'kuota_konsultasi_tumbuh_kembang' => ['required', 'integer', 'min:0', $this->konsultasiGabunganRule($request)],
        ]);

        $this->authorizeDokter($request->user(), $data['kode_dokter']);

        $doctor = Doctor::query()->findOrFail($data['kode_dokter']);

        DoctorSchedule::query()->create([
            'kode_dokter' => $doctor->kode_dokter,
            // Poliklinik dipilih eksplisit dari form (bukan diturunkan dari
            // Doctor::kode_poliklinik) - sync GTK kadang tidak mengembalikan
            // poliklinik dokter, jadwal manual harus tetap bisa dibuat lepas
            // dari lengkap/tidaknya data itu.
            'kode_poliklinik' => $data['kode_poliklinik'],
            'hari' => $data['hari'],
            'jam_mulai' => $data['jam_mulai'],
            'jam_selesai' => $data['jam_selesai'],
            'shift' => app(QuotaService::class)->bucketShift($data['jam_mulai'])->value,
            'kuota_total' => $data['kuota_total'],
            'kuota_konsultasi_gizi' => $data['kuota_konsultasi_gizi'],
            'kuota_konsultasi_tumbuh_kembang' => $data['kuota_konsultasi_tumbuh_kembang'],
            'source' => 'manual',
            'synced_at' => now(),
        ]);

        return back()->with('success', 'Jadwal ditambahkan.');
    }

    public function update(Request $request, DoctorSchedule $doctorSchedule): RedirectResponse
    {
        $this->authorizeManual($request->user(), $doctorSchedule);

        $data = $request->validate([
            'jam_mulai' => ['required', 'date_format:H:i'],
            'jam_selesai' => ['required', 'date_format:H:i', 'after:jam_mulai'],
            'kuota_total' => ['required', 'integer', 'min:0'],
            'kuota_konsultasi_gizi' => ['required', 'integer', 'min:0'],
            'kuota_konsultasi_tumbuh_kembang' => ['required', 'integer', 'min:0', $this->konsultasiGabunganRule($request)],
        ]);

        $doctorSchedule->update([
            'jam_mulai' => $data['jam_mulai'],
            'jam_selesai' => $data['jam_selesai'],
            'shift' => app(QuotaService::class)->bucketShift($data['jam_mulai'])->value,
            'kuota_total' => $data['kuota_total'],
            'kuota_konsultasi_gizi' => $data['kuota_konsultasi_gizi'],
            'kuota_konsultasi_tumbuh_kembang' => $data['kuota_konsultasi_tumbuh_kembang'],
        ]);

        return back()->with('success', 'Jadwal diperbarui.');
    }

    public function destroy(Request $request, DoctorSchedule $doctorSchedule): RedirectResponse
    {
        $this->authorizeManual($request->user(), $doctorSchedule);

        $doctorSchedule->delete();

        return back()->with('success', 'Jadwal dihapus.');
    }

    public function cancelShift(Request $request, AntreanService $antrean): RedirectResponse
    {
        $data = $this->validateShiftAction($request);

        try {
            $antrean->cancelShiftAndReschedule(
                $data['kode_dokter'],
                $data['tanggal'],
                Shift::from($data['shift']),
                $data['reason'] ?? 'Dibatalkan via dashboard',
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal membatalkan shift: '.$e->getMessage());
        }

        return back()->with('success', 'Shift dibatalkan. Pasien terdampak otomatis digeser/diberi tahu.');
    }

    public function delayShift(Request $request, AntreanService $antrean): RedirectResponse
    {
        $data = $this->validateShiftAction($request, withDelay: true);

        try {
            $antrean->delayShiftAndNotify(
                $data['kode_dokter'],
                $data['tanggal'],
                Shift::from($data['shift']),
                (int) $data['delay_minutes'],
                $data['reason'] ?? 'Delay dilaporkan via dashboard',
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal mencatat delay: '.$e->getMessage());
        }

        return back()->with('success', 'Delay dicatat. Pasien terdampak otomatis diberi tahu.');
    }

    public function reopenShift(Request $request, QuotaService $quota): RedirectResponse
    {
        $data = $this->validateShiftAction($request);

        try {
            $quota->reopenShift($data['kode_dokter'], $data['tanggal'], Shift::from($data['shift']));
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal membuka kembali shift: '.$e->getMessage());
        }

        return back()->with('success', 'Shift dibuka kembali.');
    }

    /**
     * Kustomisasi kuota untuk SATU tanggal spesifik (quota_shifts), berbeda
     * dari update()/store() di atas yang mengubah TEMPLATE mingguan
     * (doctor_schedules - berlaku ke semua tanggal berikutnya yang jatuh di
     * hari itu). Dipakai tombol "Ubah Kuota" di kartu "Status Shift".
     *
     * Baris quota_shifts utk tanggal ini mungkin belum ada sama sekali kalau
     * tanggalnya di luar jendela quota:rebuild-shifts (lihat komentar
     * --days di RebuildQuotaShifts) - resolveOrCreateQuota() membangunnya
     * on-demand dari template, sama seperti cancelShift()/delayShift().
     */
    public function updateKuotaTanggal(Request $request, QuotaService $quota): RedirectResponse
    {
        $data = $request->validate([
            'kode_dokter' => ['required', 'string', Rule::exists('doctors', 'kode_dokter')],
            'tanggal' => ['required', 'date'],
            'shift' => ['required', Rule::in(['pagi', 'sore', 'malam'])],
            'kuota_total' => ['required', 'integer', 'min:0'],
            'kuota_konsultasi_gizi' => ['required', 'integer', 'min:0'],
            'kuota_konsultasi_tumbuh_kembang' => ['required', 'integer', 'min:0', $this->konsultasiGabunganRule($request)],
        ]);

        $this->authorizeDokter($request->user(), $data['kode_dokter']);

        try {
            $quotaShift = $quota->resolveOrCreateQuota($data['kode_dokter'], $data['tanggal'], Shift::from($data['shift']));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Tidak boleh diturunkan di bawah yang sudah benar-benar terpakai/
        // dibooking - beda dari kuota.update-konsultasi (KuotaController)
        // yang membatasi lewat validasi "max" ke kuota_total statis, di sini
        // kuota_total-nya sendiri yang berubah jadi wajib dicek eksplisit.
        if ($data['kuota_total'] < $quotaShift->kuota_terpakai
            || $data['kuota_konsultasi_gizi'] < $quotaShift->kuota_terpakai_konsultasi_gizi
            || $data['kuota_konsultasi_tumbuh_kembang'] < $quotaShift->kuota_terpakai_konsultasi_tumbuh_kembang) {
            return back()->with('error', 'Kuota tidak boleh dikurangi di bawah jumlah yang sudah terpakai/dibooking untuk tanggal ini.');
        }

        $quotaShift->update([
            'kuota_total' => $data['kuota_total'],
            'kuota_konsultasi_gizi' => $data['kuota_konsultasi_gizi'],
            'kuota_konsultasi_tumbuh_kembang' => $data['kuota_konsultasi_tumbuh_kembang'],
        ]);

        return back()->with('success', 'Kuota untuk tanggal ini diperbarui.');
    }

    /**
     * @return array{kode_dokter: string, tanggal: string, shift: string, delay_minutes?: int, reason: ?string}
     */
    protected function validateShiftAction(Request $request, bool $withDelay = false): array
    {
        $rules = [
            'kode_dokter' => ['required', 'string', Rule::exists('doctors', 'kode_dokter')],
            'tanggal' => ['required', 'date'],
            'shift' => ['required', Rule::in(['pagi', 'sore', 'malam'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ];

        if ($withDelay) {
            $rules['delay_minutes'] = ['required', 'integer', 'min:1'];
        }

        $data = $request->validate($rules);

        $this->authorizeDokter($request->user(), $data['kode_dokter']);

        return $data;
    }

    protected function authorizeManual(User $user, DoctorSchedule $doctorSchedule): void
    {
        abort_if($doctorSchedule->source !== 'manual', 403, 'Jadwal hasil sinkronisasi GTK tidak bisa diubah dari sini.');

        $this->authorizeDokter($user, $doctorSchedule->kode_dokter);
    }

    protected function authorizeDokter(User $user, string $kodeDokter): void
    {
        abort_if($user->isDokter() && $kodeDokter !== $user->kode_dokter, 403);
    }

    /**
     * kuota_konsultasi_gizi + kuota_konsultasi_tumbuh_kembang gabungan tidak
     * boleh melebihi kuota_total - dua field terpisah tidak bisa dibandingkan
     * ke JUMLAH keduanya lewat rule bawaan "lte:field" (itu cuma bisa
     * membandingkan ke SATU field lain), jadi dicek manual di sini lewat
     * closure yang membaca kedua input request langsung.
     */
    protected function konsultasiGabunganRule(Request $request): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($request) {
            $gabungan = (int) $request->input('kuota_konsultasi_gizi', 0) + (int) $value;

            if ($gabungan > (int) $request->input('kuota_total', 0)) {
                $fail('Total kuota konsultasi gizi + tumbuh kembang tidak boleh melebihi kuota total.');
            }
        };
    }
}
