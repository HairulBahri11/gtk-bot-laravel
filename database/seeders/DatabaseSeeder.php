<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\ChatState;
use App\Enums\Shift;
use App\Models\Booking;
use App\Models\ChatSession;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Poliklinik;
use App\Models\QuotaShift;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed data dev/demo supaya dashboard tidak kosong saat dicek pertama
     * kali. Data master (poliklinik/dokter/jadwal) di sini hanya placeholder
     * - kelola data asli lewat dashboard (menu Poliklinik/Dokter/Jadwal
     * Dokter), TIDAK ADA LAGI perintah sync dari GTK untuk data-data ini.
     */
    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin GTK',
            'email' => 'admin@gtk.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $poliTumbuhKembang = Poliklinik::create([
            'kode_poliklinik' => '01',
            'nama_poliklinik' => 'Tumbuh Kembang Anak',
            'is_active' => true,
            'synced_at' => now(),
        ]);

        $poliFisioterapi = Poliklinik::create([
            'kode_poliklinik' => '02',
            'nama_poliklinik' => 'Fisioterapi Anak',
            'is_active' => true,
            'synced_at' => now(),
        ]);

        $dokterRina = Doctor::create([
            'kode_dokter' => 'D01',
            'nama_dokter' => 'dr. Rina Puspita',
            'kode_poliklinik' => $poliTumbuhKembang->kode_poliklinik,
            'is_active' => true,
            'synced_at' => now(),
        ]);

        $dokterBudi = Doctor::create([
            'kode_dokter' => 'D02',
            'nama_dokter' => 'dr. Budi Santoso',
            'kode_poliklinik' => $poliFisioterapi->kode_poliklinik,
            'is_active' => true,
            'synced_at' => now(),
        ]);

        User::factory()->create([
            'name' => 'dr. Rina Puspita',
            'email' => 'dokter@gtk.local',
            'password' => bcrypt('password'),
            'role' => 'dokter',
            'kode_dokter' => $dokterRina->kode_dokter,
        ]);

        $schedules = [
            [$dokterRina, $poliTumbuhKembang, 'SENIN', '08:00', '12:00', Shift::Pagi, 20],
            [$dokterRina, $poliTumbuhKembang, 'RABU', '13:00', '17:00', Shift::Sore, 15],
            [$dokterBudi, $poliFisioterapi, 'SELASA', '08:00', '11:00', Shift::Pagi, 10],
        ];

        $doctorSchedules = collect();
        $kuotaKonsultasiDefault = (int) config('gtk.kuota_konsultasi_default');

        foreach ($schedules as [$dokter, $poli, $hari, $jamMulai, $jamSelesai, $shift, $kuota]) {
            $doctorSchedules->push(DoctorSchedule::create([
                'kode_dokter' => $dokter->kode_dokter,
                'kode_poliklinik' => $poli->kode_poliklinik,
                'hari' => $hari,
                'jam_mulai' => $jamMulai,
                'jam_selesai' => $jamSelesai,
                'shift' => $shift->value,
                'kuota_total' => $kuota,
                'kuota_konsultasi_gizi' => 0,
                'kuota_konsultasi_tumbuh_kembang' => $kuotaKonsultasiDefault,
                // Jadwal source='gtk' (default) tidak lagi dipakai untuk
                // booking sama sekali - data demo ini harus 'manual' supaya
                // tetap bisa dipakai booking saat testing lokal.
                'source' => 'manual',
                'synced_at' => now(),
            ]));
        }

        $hariIndex = ['SENIN' => 1, 'SELASA' => 2, 'RABU' => 3, 'KAMIS' => 4, 'JUMAT' => 5, 'SABTU' => 6, 'MINGGU' => 7];
        $today = Carbon::today();

        for ($i = 0; $i <= 14; $i++) {
            $date = $today->copy()->addDays($i);

            foreach ($doctorSchedules as $schedule) {
                if ($hariIndex[$schedule->hari] !== $date->dayOfWeekIso) {
                    continue;
                }

                QuotaShift::create([
                    'kode_dokter' => $schedule->kode_dokter,
                    'kode_poliklinik' => $schedule->kode_poliklinik,
                    'tanggal' => $date->toDateString(),
                    'shift' => $schedule->shift->value,
                    'kuota_total' => $schedule->kuota_total,
                    'kuota_terpakai' => 0,
                    'kuota_konsultasi_gizi' => $schedule->kuota_konsultasi_gizi,
                    'kuota_terpakai_konsultasi_gizi' => 0,
                    'kuota_konsultasi_tumbuh_kembang' => $schedule->kuota_konsultasi_tumbuh_kembang,
                    'kuota_terpakai_konsultasi_tumbuh_kembang' => 0,
                    'last_synced_at' => now(),
                ]);
            }
        }

        $patient = Patient::create([
            'no_rm' => '000001',
            'nama' => 'Ahmad Nur Hakim',
            'jk' => 'LAKI-LAKI',
            'tanggal_lahir' => '2020-05-14',
            'nama_ibu_kandung' => 'Siti Aminah',
            'no_hp' => '6281234567890',
            'alamat' => 'Jl. Merdeka No. 14',
            'last_synced_at' => now(),
        ]);

        $chatSession = ChatSession::create([
            'chat_id' => '6281234567890@c.us',
            'state' => ChatState::Done->value,
            'context' => [
                'nama' => $patient->nama,
                'tanggal_lahir' => $patient->tanggal_lahir->toDateString(),
                'nama_ibu_kandung' => $patient->nama_ibu_kandung,
                'jenis_kelamin' => 'LAKI-LAKI',
                'keluhan' => 'Kontrol tumbuh kembang rutin',
                'poli_pilihan' => $poliTumbuhKembang->nama_poliklinik,
                'shift_pilihan' => 'pagi',
            ],
            'no_rm' => $patient->no_rm,
            'last_message_at' => now(),
        ]);

        $nextMonday = $today->copy()->next('Monday');

        Booking::create([
            'chat_session_id' => $chatSession->id,
            'no_rm' => $patient->no_rm,
            'no_rawat' => $nextMonday->format('Y/m/d').'/000001',
            'no_reg' => '1',
            'kode_poliklinik' => $poliTumbuhKembang->kode_poliklinik,
            'kode_dokter' => $dokterRina->kode_dokter,
            'tanggal_periksa' => $nextMonday->toDateString(),
            'shift' => Shift::Pagi->value,
            'jenis_layanan' => 'pemeriksaan',
            'status' => BookingStatus::Booked->value,
        ]);

        $this->command?->info('Login admin: admin@gtk.local / password');
        $this->command?->info('Login dokter: dokter@gtk.local / password');
    }
}
