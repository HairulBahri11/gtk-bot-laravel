<?php

namespace App\Services\Quota;

use App\Enums\Shift;
use App\Models\Booking;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Poliklinik;
use App\Models\QuotaShift;
use App\Services\Gtk\GtkApiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Kuota background": sinkronisasi & pembacaan kuota real-time per shift
 * dari cache lokal (quota_shifts), bukan langsung ke API GTK setiap kali ada
 * chat masuk (§3.1 langkah 4 PRD). Sinkronisasi dijalankan berkala oleh
 * command gtk:sync-quota.
 */
class QuotaService
{
    public function __construct(protected GtkApiService $gtk)
    {
    }

    /**
     * Tarik master poliklinik, dokter aktif, dan jadwal dokter dari GTK,
     * lalu bangun ulang snapshot kuota untuk N hari ke depan.
     */
    public function syncFromGtk(int $daysAhead = 14): void
    {
        $this->syncPoliklinik();
        $this->syncDoctors();
        $this->syncSchedules();
        $this->rebuildQuotaShifts($daysAhead);
    }

    protected function syncPoliklinik(): void
    {
        $list = $this->gtk->poliklinik()['list'] ?? [];

        foreach ($list as $item) {
            Poliklinik::updateOrCreate(
                ['kode_poliklinik' => $item['kode_poliklinik']],
                [
                    'nama_poliklinik' => $item['nama_poliklinik'],
                    'is_active' => true,
                    'synced_at' => now(),
                ],
            );
        }
    }

    protected function syncDoctors(): void
    {
        $list = $this->gtk->dokterAktif()['list'] ?? [];

        foreach ($list as $item) {
            Doctor::updateOrCreate(
                ['kode_dokter' => $item['kode_dokter']],
                [
                    'nama_dokter' => $item['nama_dokter'],
                    'kode_poliklinik' => $item['kode_poliklinik'] ?? null,
                    'is_active' => true,
                    'synced_at' => now(),
                ],
            );
        }
    }

    protected function syncSchedules(): void
    {
        /** @var array<int, string> $kodeDokterList */
        $kodeDokterList = Doctor::query()->pluck('kode_dokter')->all();

        // GET /jadwaldokter hanya mengembalikan NAMA poliklinik ("poliklinik"),
        // bukan kode-nya - resolve lewat tabel poliklinik lokal (sudah
        // disinkronkan di syncPoliklinik(), dipanggil lebih dulu di
        // syncFromGtk()).
        $kodeByNama = Poliklinik::query()->pluck('kode_poliklinik', 'nama_poliklinik');

        foreach ($kodeDokterList as $kodeDokter) {
            try {
                $result = $this->gtk->jadwalDokter(['kodedokter' => $kodeDokter]);
            } catch (\Throwable $e) {
                Log::warning('Gagal sync jadwal dokter', ['kode_dokter' => $kodeDokter, 'error' => $e->getMessage()]);

                continue;
            }

            // Response bisa berupa 1 object (satu dokter) atau list.
            $entries = isset($result['kode_dokter']) ? [$result] : ($result['list'] ?? [$result]);

            foreach ($entries as $entry) {
                $kodePoli = $entry['kode_poliklinik'] ?? $kodeByNama[$entry['poliklinik'] ?? ''] ?? null;

                if (! $kodePoli) {
                    Log::warning('Lewati jadwal dokter - poliklinik tidak dikenali', [
                        'kode_dokter' => $entry['kode_dokter'] ?? $kodeDokter,
                        'poliklinik' => $entry['poliklinik'] ?? null,
                    ]);

                    continue;
                }

                foreach ($entry['jadwal'] ?? [] as $jadwal) {
                    // Lewati baris jadwal kosong/tidak valid (kuota 0 atau
                    // jam_mulai == jam_selesai) - sejumlah dokter mengembalikan
                    // baris placeholder seperti ini dari GTK.
                    if ((int) ($jadwal['kuota'] ?? 0) <= 0 || $jadwal['jam_mulai'] === $jadwal['jam_selesai']) {
                        continue;
                    }

                    $hari = $this->normalizeHari($jadwal['hari']);
                    $shift = $this->bucketShift($jadwal['jam_mulai']);

                    DoctorSchedule::updateOrCreate(
                        [
                            'kode_dokter' => $entry['kode_dokter'],
                            'hari' => $hari,
                            'jam_mulai' => $jadwal['jam_mulai'],
                        ],
                        [
                            'kode_poliklinik' => $kodePoli,
                            'jam_selesai' => $jadwal['jam_selesai'],
                            'shift' => $shift->value,
                            'kuota_total' => $jadwal['kuota'] ?? 0,
                            'synced_at' => now(),
                        ],
                    );
                }
            }
        }
    }

    protected function rebuildQuotaShifts(int $daysAhead): void
    {
        $schedules = DoctorSchedule::all();
        $today = Carbon::today();

        $englishToHari = [
            'Monday' => 'SENIN', 'Tuesday' => 'SELASA', 'Wednesday' => 'RABU',
            'Thursday' => 'KAMIS', 'Friday' => 'JUMAT', 'Saturday' => 'SABTU', 'Sunday' => 'MINGGU',
        ];

        for ($i = 0; $i <= $daysAhead; $i++) {
            $date = $today->copy()->addDays($i);
            $hari = $englishToHari[$date->format('l')];

            foreach ($schedules->where('hari', $hari) as $schedule) {
                $terpakai = Booking::query()
                    ->where('kode_dokter', $schedule->kode_dokter)
                    ->whereDate('tanggal_periksa', $date)
                    ->where('shift', $schedule->shift->value)
                    ->whereIn('status', ['booked', 'confirmed', 'arrived'])
                    ->count();

                QuotaShift::updateOrCreate(
                    [
                        'kode_dokter' => $schedule->kode_dokter,
                        'tanggal' => $date->toDateString(),
                        'shift' => $schedule->shift->value,
                    ],
                    [
                        'kode_poliklinik' => $schedule->kode_poliklinik,
                        'kuota_total' => $schedule->kuota_total,
                        'kuota_terpakai' => $terpakai,
                        'last_synced_at' => now(),
                    ],
                );
            }
        }
    }

    public function bucketShift(string $jamMulai): Shift
    {
        $windows = config('gtk.shift_windows');
        $time = substr($jamMulai, 0, 5);

        foreach ($windows as $key => $window) {
            if ($time >= $window['start'] && $time < $window['end']) {
                return Shift::from($key);
            }
        }

        return Shift::Malam;
    }

    /**
     * Nilai hari 'AKHAD' dari database GTK dikonversi menjadi 'MINGGU'
     * agar konsisten dengan enum lokal (§7.7.2 PRD).
     */
    protected function normalizeHari(string $hari): string
    {
        $hari = strtoupper($hari);

        return $hari === 'AKHAD' ? 'MINGGU' : $hari;
    }

    public function findQuota(string $kodeDokter, string $tanggal, Shift $shift): ?QuotaShift
    {
        return QuotaShift::query()
            ->where('kode_dokter', $kodeDokter)
            ->whereDate('tanggal', $tanggal)
            ->where('shift', $shift->value)
            ->first();
    }

    public function hasAvailability(string $kodeDokter, string $tanggal, Shift $shift): bool
    {
        $quota = $this->findQuota($kodeDokter, $tanggal, $shift);

        return $quota !== null && $quota->kuota_tersisa > 0;
    }

    /**
     * Cari alternatif shift/tanggal terdekat yang masih tersedia untuk
     * dokter yang sama, dipakai saat kuota penuh (§3.2 PRD).
     *
     * @return array<int, array{tanggal: string, shift: string}>
     */
    public function suggestAlternatives(string $kodeDokter, string $tanggal, int $limit = 3): array
    {
        return QuotaShift::query()
            ->where('kode_dokter', $kodeDokter)
            ->whereDate('tanggal', '>=', $tanggal)
            ->whereColumn('kuota_terpakai', '<', 'kuota_total')
            ->orderBy('tanggal')
            ->orderByRaw("CASE shift WHEN 'pagi' THEN 1 WHEN 'sore' THEN 2 WHEN 'malam' THEN 3 ELSE 4 END")
            ->limit($limit)
            ->get()
            ->map(fn (QuotaShift $q) => [
                'tanggal' => $q->tanggal->toDateString(),
                'shift' => $q->shift->value,
            ])
            ->all();
    }

    public function reserveSlot(string $kodeDokter, string $tanggal, Shift $shift, int $amount = 1): void
    {
        DB::table('quota_shifts')
            ->where('kode_dokter', $kodeDokter)
            ->whereDate('tanggal', $tanggal)
            ->where('shift', $shift->value)
            ->increment('kuota_terpakai', $amount);
    }

    public function releaseSlot(string $kodeDokter, string $tanggal, Shift $shift, int $amount = 1): void
    {
        $quota = $this->findQuota($kodeDokter, $tanggal, $shift);

        if (! $quota) {
            return;
        }

        $quota->kuota_terpakai = max(0, $quota->kuota_terpakai - $amount);
        $quota->save();
    }

    /**
     * Geser buffer antrean (§3.2 PRD) sebanyak N kuota tambahan pada shift
     * terkait, untuk menampung promosi waitlist setelah No-Show.
     */
    public function shiftBuffer(string $kodeDokter, string $tanggal, Shift $shift, int $amount): void
    {
        $quota = $this->findQuota($kodeDokter, $tanggal, $shift);

        if (! $quota) {
            return;
        }

        $quota->kuota_total += $amount;
        $quota->save();
    }
}
