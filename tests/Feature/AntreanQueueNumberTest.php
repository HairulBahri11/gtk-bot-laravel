<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\JenisLayanan;
use App\Enums\Shift;
use App\Models\Booking;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Poliklinik;
use App\Models\User;
use App\Services\Antrean\AntreanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AntreanQueueNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function seedDoctor(string $kodeDokter = 'D01', string $namaDokter = 'dr. Rina Puspita'): Doctor
    {
        Poliklinik::query()->firstOrCreate(
            ['kode_poliklinik' => '01'],
            ['nama_poliklinik' => 'Tumbuh Kembang Anak', 'is_active' => true],
        );

        return Doctor::create([
            'kode_dokter' => $kodeDokter,
            'nama_dokter' => $namaDokter,
            'kode_poliklinik' => '01',
            'is_active' => true,
        ]);
    }

    protected function makeBooking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'no_rm' => '000'.random_int(100, 999),
            'kode_poliklinik' => '01',
            'kode_dokter' => 'D01',
            'tanggal_periksa' => now()->toDateString(),
            'shift' => Shift::Pagi->value,
            'jenis_layanan' => JenisLayanan::Pemeriksaan->value,
            'status' => BookingStatus::Booked->value,
        ], $overrides));
    }

    /**
     * Klik "Datang" pertama WAJIB dapat #1, klik kedua (dokter+tanggal+shift
     * yang sama) WAJIB dapat #2 - lihat AntreanService::nextQueueNumber().
     */
    public function test_confirm_arrival_assigns_sequential_queue_numbers(): void
    {
        $this->seedDoctor();

        $antrean = app(AntreanService::class);

        $first = $this->makeBooking();
        $second = $this->makeBooking();

        $antrean->confirmArrival($first);
        $antrean->confirmArrival($second);

        $this->assertSame(1, $first->fresh()->no_antrean);
        $this->assertSame(2, $second->fresh()->no_antrean);
    }

    /**
     * Keputusan produk yang sudah dikonfirmasi: nomor antrian SATU urutan
     * gabungan per dokter+tanggal+shift, TIDAK dipisah per jenis_layanan
     * (beda dari waitlist_position yang sengaja terisolasi per kategori) -
     * pasien Konsultasi Gizi & Pemeriksaan pada shift yang sama HARUS
     * berbagi satu urutan, bukan sama-sama dapat #1.
     */
    public function test_queue_number_is_shared_across_jenis_layanan(): void
    {
        $this->seedDoctor();

        $antrean = app(AntreanService::class);

        $pemeriksaan = $this->makeBooking(['jenis_layanan' => JenisLayanan::Pemeriksaan->value]);
        $konsultasiGizi = $this->makeBooking(['jenis_layanan' => JenisLayanan::KonsultasiGizi->value]);

        $antrean->confirmArrival($pemeriksaan);
        $antrean->confirmArrival($konsultasiGizi);

        $this->assertSame(1, $pemeriksaan->fresh()->no_antrean);
        $this->assertSame(2, $konsultasiGizi->fresh()->no_antrean);
    }

    /**
     * Scope nomor antrian: dokter+tanggal+shift yang BEDA harus mulai lagi
     * dari #1, tidak melanjutkan urutan dokter/shift/tanggal lain.
     */
    public function test_queue_number_resets_per_doctor_date_and_shift(): void
    {
        $this->seedDoctor('D01', 'dr. Rina Puspita');
        $this->seedDoctor('D02', 'dr. Doni Saputra');

        $antrean = app(AntreanService::class);

        $doctorOne = $this->makeBooking(['kode_dokter' => 'D01']);
        $antrean->confirmArrival($doctorOne);
        $this->assertSame(1, $doctorOne->fresh()->no_antrean);

        $doctorTwo = $this->makeBooking(['kode_dokter' => 'D02']);
        $antrean->confirmArrival($doctorTwo);
        $this->assertSame(1, $doctorTwo->fresh()->no_antrean, 'dokter berbeda wajib mulai dari #1 lagi');

        $differentShift = $this->makeBooking(['kode_dokter' => 'D01', 'shift' => Shift::Sore->value]);
        $antrean->confirmArrival($differentShift);
        $this->assertSame(1, $differentShift->fresh()->no_antrean, 'shift berbeda wajib mulai dari #1 lagi');

        $differentDate = $this->makeBooking(['kode_dokter' => 'D01', 'tanggal_periksa' => now()->addDay()->toDateString()]);
        $antrean->confirmArrival($differentDate);
        $this->assertSame(1, $differentDate->fresh()->no_antrean, 'tanggal berbeda wajib mulai dari #1 lagi');
    }

    /**
     * confirmArrival() dipanggil dua kali untuk booking yang sama TIDAK
     * boleh memajukan nomor - idempoten (lihat guard "??" di
     * AntreanService::confirmArrival()).
     */
    public function test_confirm_arrival_is_idempotent(): void
    {
        $this->seedDoctor();

        $antrean = app(AntreanService::class);
        $booking = $this->makeBooking();

        $antrean->confirmArrival($booking);
        $antrean->confirmArrival($booking->fresh());

        $this->assertSame(1, $booking->fresh()->no_antrean);
    }

    /**
     * Halaman Kuota (kuota.index) WAJIB mengembalikan antrean terurut naik
     * berdasarkan no_antrean, dan filter "dokter" WAJIB mempersempit ke
     * dokter itu saja.
     */
    public function test_kuota_index_returns_ordered_and_filterable_antrean(): void
    {
        $this->seedDoctor('D01', 'dr. Rina Puspita');
        $this->seedDoctor('D02', 'dr. Doni Saputra');

        foreach (['000201', '000202', '000203'] as $i => $noRm) {
            Patient::create([
                'no_rm' => $noRm,
                'nama' => "Pasien {$i}",
                'jk' => 'LAKI-LAKI',
                'tanggal_lahir' => '2021-01-01',
                'nama_ibu_kandung' => 'Sari',
                'no_hp' => '08123456789'.$i,
                'last_synced_at' => now(),
            ]);
        }

        $antrean = app(AntreanService::class);

        // Dibuat (jadi id-nya) DULUAN, tapi datang BELAKANGAN (no_antrean
        // #2) - sengaja dibalik dari urutan insert supaya assertion di
        // bawah benar-benar membuktikan hasilnya terurut oleh no_antrean,
        // BUKAN kebetulan sama dengan urutan id/insert.
        $createdFirstArrivesSecond = $this->makeBooking(['kode_dokter' => 'D01', 'no_rm' => '000201']);
        $createdSecondArrivesFirst = $this->makeBooking(['kode_dokter' => 'D01', 'no_rm' => '000202']);

        $antrean->confirmArrival($createdSecondArrivesFirst);
        $antrean->confirmArrival($createdFirstArrivesSecond);

        // Dokter lain, no_antrean-nya SENDIRI (mulai dari #1 lagi) - dengan
        // no_rm berbeda supaya tidak tertukar dengan dua booking D01 di atas
        // saat memverifikasi urutan hasil di bawah.
        $lainDokter = $this->makeBooking(['kode_dokter' => 'D02', 'no_rm' => '000203']);
        $antrean->confirmArrival($lainDokter);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/pre-layanan/kuota');
        $response->assertOk();
        // Urutan primer no_antrean ASC, sekunder kode_dokter ASC (lihat
        // KuotaController::index()) - no_antrean HANYA unik per dokter,
        // jadi D01 #1 & D02 #1 sama-sama "peringkat 1" tapi D01 < D02
        // secara alfabet menentukan urutan tampil di antara keduanya.
        $response->assertInertia(fn ($page) => $page
            ->component('Kuota/Index')
            ->has('antrean', 3)
            ->where('antrean.0.no_rm', '000202') // D01, no_antrean #1
            ->where('antrean.0.no_antrean', 1)
            ->where('antrean.1.no_rm', '000203') // D02, no_antrean #1
            ->where('antrean.1.no_antrean', 1)
            ->where('antrean.2.no_rm', '000201') // D01, no_antrean #2
            ->where('antrean.2.no_antrean', 2)
        );

        $filtered = $this->actingAs($user)->get('/pre-layanan/kuota?dokter=D02');
        $filtered->assertOk();
        $filtered->assertInertia(fn ($page) => $page
            ->component('Kuota/Index')
            ->has('antrean', 1)
            ->where('antrean.0.dokter', 'dr. Doni Saputra')
        );
    }
}
