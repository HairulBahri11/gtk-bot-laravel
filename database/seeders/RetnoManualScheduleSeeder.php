<?php

namespace Database\Seeders;

use App\Enums\Shift;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Poliklinik;
use App\Services\Quota\QuotaService;
use Illuminate\Database\Seeder;

/**
 * Jadwal manual dr. RA Retno Wulandari, SpA (Poli Spesialis Anak) - GTK
 * /jadwaldokter tidak punya data untuk dokter ini, jadi jadwalnya diinput
 * manual di sini (source='manual', tidak pernah ditimpa QuotaService::
 * syncSchedules() - lihat migration add_source_to_doctor_schedules_table).
 *
 * TIDAK didaftarkan di DatabaseSeeder::run() supaya tidak ikut jalan setiap
 * `php artisan db:seed` - jalankan sekali secara eksplisit:
 *   php artisan db:seed --class=RetnoManualScheduleSeeder
 *
 * kuota_total 15/shift dipakai sebagai default awal (belum ada angka resmi
 * di luar diskusi tim yang men-scope ulang alokasi Gizi/Tumbuh Kembang
 * secara terpisah dari revisi ini) - bisa diubah kapan pun lewat dashboard
 * Jadwal Dokter. Dari 15 itu, 1 kuota dialokasikan ke Konsultasi Tumbuh
 * Kembang (Gizi mulai dari 0, sisanya 14 ke Pemeriksaan Sakit/Imunisasi
 * secara turunan - lihat DoctorSchedule::getKuotaPemeriksaanAttribute()) -
 * juga bisa disesuaikan lewat dashboard.
 */
class RetnoManualScheduleSeeder extends Seeder
{
    public const KODE_DOKTER = 'MANUAL-RETNO';

    protected const DEFAULT_KUOTA = 15;

    protected const DEFAULT_KUOTA_KONSULTASI_GIZI = 0;

    protected const DEFAULT_KUOTA_KONSULTASI_TUMBUH_KEMBANG = 1;

    public function run(): void
    {
        $poli = Poliklinik::query()->firstOrCreate(
            ['nama_poliklinik' => 'Poli Spesialis Anak'],
            ['kode_poliklinik' => 'MANUAL-ANAK', 'is_active' => true, 'synced_at' => now()],
        );

        $doctor = Doctor::query()->updateOrCreate(
            ['kode_dokter' => self::KODE_DOKTER],
            [
                'nama_dokter' => 'dr. RA Retno Wulandari, SpA',
                'kode_poliklinik' => $poli->kode_poliklinik,
                'is_active' => true,
                'synced_at' => now(),
            ],
        );

        $quota = app(QuotaService::class);

        $weekdayShifts = [
            ['SENIN', '08:00', '09:30'],
            ['SENIN', '15:30', '17:00'],
            ['SENIN', '18:30', '20:00'],
            ['SELASA', '08:00', '09:30'],
            ['SELASA', '15:30', '17:00'],
            ['SELASA', '18:30', '20:00'],
            ['RABU', '08:00', '09:30'],
            ['RABU', '15:30', '17:00'],
            ['RABU', '18:30', '20:00'],
            ['KAMIS', '08:00', '09:30'],
            ['KAMIS', '15:30', '17:00'],
            ['KAMIS', '18:30', '20:00'],
            ['JUMAT', '08:00', '09:30'],
            ['JUMAT', '15:30', '17:00'],
            ['JUMAT', '18:30', '20:00'],
            ['SABTU', '08:00', '11:00'],
            ['SABTU', '15:30', '17:00'],
        ];

        foreach ($weekdayShifts as [$hari, $jamMulai, $jamSelesai]) {
            DoctorSchedule::query()->updateOrCreate(
                ['kode_dokter' => $doctor->kode_dokter, 'hari' => $hari, 'jam_mulai' => $jamMulai],
                [
                    'kode_poliklinik' => $poli->kode_poliklinik,
                    'jam_selesai' => $jamSelesai,
                    'shift' => $quota->bucketShift($jamMulai)->value,
                    'kuota_total' => self::DEFAULT_KUOTA,
                    'kuota_konsultasi_gizi' => self::DEFAULT_KUOTA_KONSULTASI_GIZI,
                    'kuota_konsultasi_tumbuh_kembang' => self::DEFAULT_KUOTA_KONSULTASI_TUMBUH_KEMBANG,
                    'source' => 'manual',
                    'synced_at' => now(),
                ],
            );
        }

        $this->command?->info('Jadwal manual dr. RA Retno Wulandari, SpA berhasil di-seed (kode_dokter: '.self::KODE_DOKTER.').');
        $this->command?->info('Jalankan `php artisan gtk:sync-quota` untuk membangun quota_shifts dari jadwal ini.');
    }
}
