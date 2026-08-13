<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
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
            ->get(['id', 'status']);

        $dikonfirmasiHariIni = $bookingsToday->where('status', BookingStatus::Confirmed)->count();
        $sudahDatangHariIni = $bookingsToday->where('status', BookingStatus::Arrived)->count();

        $menungguTerjadwalHariIni = $bookingsToday
            ->whereIn('status', [BookingStatus::Booked, BookingStatus::Confirmed, BookingStatus::Waitlist])
            ->count();

        $batalNoShowHariIni = $bookingsToday
            ->whereIn('status', [BookingStatus::Cancelled, BookingStatus::NoShow])
            ->count();

        $quotaToday = QuotaShift::query()
            ->when($user->isDokter(), fn ($q) => $q->where('kode_dokter', $user->kode_dokter))
            ->whereDate('tanggal', $today)
            ->get();

        // "Live sync" jujur berdasarkan data - bukan sekadar hiasan: hijau
        // hanya kalau snapshot kuota hari ini benar-benar baru disinkronkan
        // (job gtk:sync-quota berjalan tiap 10 menit).
        $lastSyncedAt = $quotaToday->max('last_synced_at');
        $liveSync = $lastSyncedAt && Carbon::parse($lastSyncedAt)->diffInMinutes(now()) <= 15;

        $quotaByPoliklinik = $this->quotaByPoliklinikToday($quotaToday, $user->isDokter() ? $user->kode_dokter : null);

        $doctorName = null;

        if ($user->isDokter() && $user->kode_dokter) {
            $doctorName = Doctor::query()->find($user->kode_dokter)?->nama_dokter;
        }

        return Inertia::render('Dashboard/Index', [
            'clinic' => [
                'doctor_name' => $doctorName,
                'live_sync' => $liveSync,
            ],
            'stats' => [
                'total_pasien_hari_ini' => $bookingsToday->count(),
                'dikonfirmasi_hari_ini' => $dikonfirmasiHariIni,
                'sudah_datang_hari_ini' => $sudahDatangHariIni,
                'menunggu_terjadwal_hari_ini' => $menungguTerjadwalHariIni,
                'batal_no_show_hari_ini' => $batalNoShowHariIni,
            ],
            'quotaByPoliklinik' => $quotaByPoliklinik,
        ]);
    }

    /**
     * Seluruh jadwal kuota HARI INI, dikelompokkan per poliklinik lalu per
     * shift, lengkap dengan rentang jam operasional & nama dokternya - supaya
     * admin/dokter bisa lihat sekilas semua jadwal tanpa harus buka halaman
     * Kuota terpisah.
     *
     * DoctorSchedule (template mingguan) dipakai sebagai SUMBER KEBENARAN
     * untuk poliklinik/shift APA SAJA yang aktif hari ini - bukan QuotaShift,
     * yang cuma snapshot hasil sinkronisasi berkala (gtk:sync-quota tiap 10
     * menit) dan bisa saja belum lengkap/tertinggal untuk tanggal ini. Angka
     * used/total diambil dari QuotaShift kalau sudah tersinkron untuk
     * kombinasi dokter+shift itu; kalau belum, fallback ke kuota_total dari
     * jadwal template (used dianggap 0, bukan disembunyikan/dihilangkan).
     *
     * @return array<int, array{poliklinik: string, shifts: array<int, array{shift: string, time_range: string, dokter: string, total: int, used: int, tersinkron: bool}>}>
     */
    private function quotaByPoliklinikToday($quotaToday, ?string $kodeDokter): array
    {
        $hariIndo = [
            'Monday' => 'SENIN', 'Tuesday' => 'SELASA', 'Wednesday' => 'RABU', 'Thursday' => 'KAMIS',
            'Friday' => 'JUMAT', 'Saturday' => 'SABTU', 'Sunday' => 'MINGGU',
        ];
        $hariIni = $hariIndo[Carbon::today()->format('l')];
        $shiftOrder = ['pagi' => 1, 'sore' => 2, 'malam' => 3];

        $schedulesToday = DoctorSchedule::query()
            ->with(['poliklinik', 'doctor'])
            ->when($kodeDokter, fn ($q) => $q->where('kode_dokter', $kodeDokter))
            ->where('hari', $hariIni)
            ->where('source', 'manual')
            ->get();

        $quotaByDokterShift = $quotaToday->keyBy(fn (QuotaShift $q) => $q->kode_dokter.'|'.$q->shift->value);

        return $schedulesToday
            ->groupBy('kode_poliklinik')
            ->map(function ($rows, $kodePoliklinik) use ($quotaByDokterShift, $shiftOrder) {
                $shifts = $rows
                    ->groupBy(fn (DoctorSchedule $s) => $s->shift->value)
                    ->map(function ($shiftRows, $shift) use ($quotaByDokterShift) {
                        $total = 0;
                        $used = 0;
                        $tersinkron = true;

                        foreach ($shiftRows as $s) {
                            $quota = $quotaByDokterShift->get("{$s->kode_dokter}|{$shift}");

                            if ($quota) {
                                $total += $quota->kuota_total;
                                $used += $quota->kuota_terpakai;
                            } else {
                                $total += $s->kuota_total;
                                $tersinkron = false;
                            }
                        }

                        return [
                            'shift' => $shift,
                            'time_range' => $this->formatJamRange($shiftRows->min('jam_mulai'), $shiftRows->max('jam_selesai')),
                            'dokter' => $shiftRows->pluck('doctor.nama_dokter')->filter()->unique()->values()->implode(', '),
                            'total' => $total,
                            'used' => $used,
                            'tersinkron' => $tersinkron,
                        ];
                    })
                    ->sortBy(fn ($s) => $shiftOrder[$s['shift']] ?? 99)
                    ->values();

                return [
                    'poliklinik' => $rows->first()->poliklinik?->nama_poliklinik ?? $kodePoliklinik,
                    'shifts' => $shifts,
                ];
            })
            ->sortBy('poliklinik')
            ->values()
            ->all();
    }

    private function formatJamRange(string $jamMulai, string $jamSelesai): string
    {
        $mulai = str_replace(':', '.', substr($jamMulai, 0, 5));
        $selesai = str_replace(':', '.', substr($jamSelesai, 0, 5));

        return "{$mulai} – {$selesai}";
    }
}
