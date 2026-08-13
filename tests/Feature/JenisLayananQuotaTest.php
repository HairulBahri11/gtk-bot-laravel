<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\JenisLayanan;
use App\Enums\Shift;
use App\Models\Booking;
use App\Models\ChatSession;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Poliklinik;
use App\Models\QuotaShift;
use App\Services\Antrean\AntreanService;
use App\Services\Quota\QuotaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Setiap shift membagi kuota_total jadi dua pool TERISOLASI - Pemeriksaan/
 * Imunisasi dan Konsultasi (lihat QuotaShift::tersisaFor()) - bukan cuma dua
 * angka dekoratif. Ini akar dari seluruh fitur pemisahan kuota, jadi
 * diuji terpisah dari DoctorShiftCancellationTest.php yang fokus ke
 * cancel/delay shift.
 */
class JenisLayananQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected string $kodeDokter = 'MANUAL-D01';

    protected string $kodePoliklinik = '01';

    protected string $tanggal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tanggal = now()->addDay()->toDateString();

        Http::fake([
            '*url=auth*' => Http::response(['response' => ['token' => 'test-token'], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200),
            '*url=batalkunjungan*' => Http::response(['response' => [], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200),
            '*url=regpasien*' => Http::response(['response' => ['no_rawat' => 'RAWAT-X', 'no_reg' => '1'], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200),
        ]);

        Poliklinik::create(['kode_poliklinik' => $this->kodePoliklinik, 'nama_poliklinik' => 'Poli Spesialis Anak', 'is_active' => true]);
        Doctor::create(['kode_dokter' => $this->kodeDokter, 'nama_dokter' => 'dr. Retno', 'kode_poliklinik' => $this->kodePoliklinik, 'is_active' => true]);
    }

    protected function hariFor(string $tanggal): string
    {
        $map = [
            'Monday' => 'SENIN', 'Tuesday' => 'SELASA', 'Wednesday' => 'RABU',
            'Thursday' => 'KAMIS', 'Friday' => 'JUMAT', 'Saturday' => 'SABTU', 'Sunday' => 'MINGGU',
        ];

        return $map[Carbon::parse($tanggal)->format('l')];
    }

    protected function makeSchedule(string $shift, int $kuotaTotal = 15, int $kuotaKonsultasi = 1): void
    {
        DoctorSchedule::create([
            'kode_dokter' => $this->kodeDokter,
            'kode_poliklinik' => $this->kodePoliklinik,
            'hari' => $this->hariFor($this->tanggal),
            'jam_mulai' => '08:00',
            'jam_selesai' => '09:30',
            'shift' => $shift,
            'kuota_total' => $kuotaTotal,
            'kuota_konsultasi' => $kuotaKonsultasi,
            'source' => 'manual',
        ]);
    }

    protected function makeQuotaShift(
        string $shift,
        int $kuotaTotal,
        int $kuotaTerpakai,
        int $kuotaKonsultasi,
        int $kuotaTerpakaiKonsultasi,
    ): QuotaShift {
        return QuotaShift::create([
            'kode_dokter' => $this->kodeDokter,
            'kode_poliklinik' => $this->kodePoliklinik,
            'tanggal' => $this->tanggal,
            'shift' => $shift,
            'kuota_total' => $kuotaTotal,
            'kuota_terpakai' => $kuotaTerpakai,
            'kuota_konsultasi' => $kuotaKonsultasi,
            'kuota_terpakai_konsultasi' => $kuotaTerpakaiKonsultasi,
            'status' => 'open',
        ]);
    }

    protected function makePatientAndSession(string $noRm): ChatSession
    {
        Patient::create([
            'no_rm' => $noRm,
            'nama' => "Pasien {$noRm}",
            'jk' => 'LAKI-LAKI',
            'tanggal_lahir' => '2020-01-01',
            'nama_ibu_kandung' => 'Ibu',
            'no_hp' => '081234567890',
        ]);

        return ChatSession::create([
            'chat_id' => "628{$noRm}@c.us",
            'state' => 'STATE_2_KONFIRMASI',
            'context' => [],
            'no_rm' => $noRm,
        ]);
    }

    public function test_konsultasi_full_does_not_block_pemeriksaan_booking(): void
    {
        $this->makeSchedule('pagi');
        // Total 2: konsultasi (1) penuh terpakai, pemeriksaan (1) masih kosong.
        $this->makeQuotaShift('pagi', kuotaTotal: 2, kuotaTerpakai: 1, kuotaKonsultasi: 1, kuotaTerpakaiKonsultasi: 1);

        $session = $this->makePatientAndSession('000001');

        $booking = app(AntreanService::class)->createBooking($session, [
            'no_rm' => '000001',
            'kode_poliklinik' => $this->kodePoliklinik,
            'kode_dokter' => $this->kodeDokter,
            'tanggal_periksa' => $this->tanggal,
            'shift' => Shift::Pagi,
            'jenis_layanan' => JenisLayanan::Pemeriksaan,
        ]);

        $this->assertSame(BookingStatus::Booked, $booking->status);
    }

    public function test_pemeriksaan_full_does_not_block_konsultasi_booking(): void
    {
        $this->makeSchedule('pagi');
        // Total 2: konsultasi (1) masih kosong, pemeriksaan (1) penuh terpakai.
        $this->makeQuotaShift('pagi', kuotaTotal: 2, kuotaTerpakai: 1, kuotaKonsultasi: 1, kuotaTerpakaiKonsultasi: 0);

        $session = $this->makePatientAndSession('000002');

        $booking = app(AntreanService::class)->createBooking($session, [
            'no_rm' => '000002',
            'kode_poliklinik' => $this->kodePoliklinik,
            'kode_dokter' => $this->kodeDokter,
            'tanggal_periksa' => $this->tanggal,
            'shift' => Shift::Pagi,
            'jenis_layanan' => JenisLayanan::Konsultasi,
        ]);

        $this->assertSame(BookingStatus::Booked, $booking->status);
    }

    public function test_booking_waitlisted_when_own_category_full_even_if_other_category_has_room(): void
    {
        $this->makeSchedule('pagi');
        // konsultasi (1) penuh, pemeriksaan (1) masih kosong - tapi pasien
        // ini minta KONSULTASI, jadi tetap wajib waitlist.
        $this->makeQuotaShift('pagi', kuotaTotal: 2, kuotaTerpakai: 1, kuotaKonsultasi: 1, kuotaTerpakaiKonsultasi: 1);

        $session = $this->makePatientAndSession('000003');

        $booking = app(AntreanService::class)->createBooking($session, [
            'no_rm' => '000003',
            'kode_poliklinik' => $this->kodePoliklinik,
            'kode_dokter' => $this->kodeDokter,
            'tanggal_periksa' => $this->tanggal,
            'shift' => Shift::Pagi,
            'jenis_layanan' => JenisLayanan::Konsultasi,
        ]);

        $this->assertSame(BookingStatus::Waitlist, $booking->status);
        $this->assertSame(1, $booking->waitlist_position);
    }

    public function test_waitlist_promotion_only_promotes_matching_category(): void
    {
        $this->makeSchedule('pagi');
        $this->makeQuotaShift('pagi', kuotaTotal: 2, kuotaTerpakai: 0, kuotaKonsultasi: 1, kuotaTerpakaiKonsultasi: 0);

        $sessionA = $this->makePatientAndSession('000004');
        $bookingPemeriksaan = Booking::create([
            'chat_session_id' => $sessionA->id,
            'no_rm' => '000004',
            'kode_poliklinik' => $this->kodePoliklinik,
            'kode_dokter' => $this->kodeDokter,
            'tanggal_periksa' => $this->tanggal,
            'shift' => 'pagi',
            'jenis_layanan' => 'pemeriksaan',
            'status' => BookingStatus::Waitlist->value,
            'waitlist_position' => 1,
        ]);

        $sessionB = $this->makePatientAndSession('000005');
        $bookingKonsultasi = Booking::create([
            'chat_session_id' => $sessionB->id,
            'no_rm' => '000005',
            'kode_poliklinik' => $this->kodePoliklinik,
            'kode_dokter' => $this->kodeDokter,
            'tanggal_periksa' => $this->tanggal,
            'shift' => 'pagi',
            'jenis_layanan' => 'konsultasi',
            'status' => BookingStatus::Waitlist->value,
            'waitlist_position' => 1,
        ]);

        // Satu slot PEMERIKSAAN terbuka (mis. batal) - promosikan HANYA
        // waitlist kategori pemeriksaan, waitlist konsultasi tidak disentuh
        // walau posisinya sama-sama #1.
        app(AntreanService::class)->promoteWaitlist($this->kodeDokter, $this->tanggal, Shift::Pagi, JenisLayanan::Pemeriksaan, 1);

        $bookingPemeriksaan->refresh();
        $bookingKonsultasi->refresh();

        $this->assertSame(BookingStatus::Booked, $bookingPemeriksaan->status);
        $this->assertSame(BookingStatus::Waitlist, $bookingKonsultasi->status);
    }

    /**
     * Regresi paling penting dari fitur ini: kuota_konsultasi adalah
     * ALOKASI yang admin atur manual dari dashboard - resync berkala
     * (gtk:sync-quota, tiap 10 menit) TIDAK BOLEH menimpanya balik ke
     * default template, sama seperti status/delay_minutes/reason yang
     * sudah dilindungi lebih dulu. kuota_terpakai_konsultasi sebaliknya
     * WAJIB direkomputasi tiap sync (fakta terpakai, bukan target admin).
     */
    public function test_sync_protects_manual_kuota_konsultasi_but_recomputes_usage(): void
    {
        $this->makeSchedule('pagi', kuotaTotal: 15, kuotaKonsultasi: 1);
        $quotaShift = $this->makeQuotaShift('pagi', kuotaTotal: 15, kuotaTerpakai: 0, kuotaKonsultasi: 1, kuotaTerpakaiKonsultasi: 0);

        // Admin menaikkan alokasi konsultasi HARI INI secara manual dari
        // dashboard (mis. kuota pemeriksaan sepi).
        $quotaShift->update(['kuota_konsultasi' => 4]);

        $sessionKonsultasi = $this->makePatientAndSession('000006');
        Booking::create([
            'chat_session_id' => $sessionKonsultasi->id,
            'no_rm' => '000006',
            'kode_poliklinik' => $this->kodePoliklinik,
            'kode_dokter' => $this->kodeDokter,
            'tanggal_periksa' => $this->tanggal,
            'shift' => 'pagi',
            'jenis_layanan' => 'konsultasi',
            'status' => BookingStatus::Booked->value,
        ]);

        // Dokter ini bersumber 'manual', jadi QuotaService::syncSchedules()
        // melewatinya sepenuhnya (tidak pernah panggil jadwaldokter) -
        // hanya poliklinik/dokteraktif yang perlu di-fake di sini.
        Http::fake([
            '*url=auth*' => Http::response(['response' => ['token' => 'test-token'], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200),
            '*url=poliklinik*' => Http::response(['response' => ['list' => []], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200),
            '*url=dokteraktif*' => Http::response(['response' => ['list' => []], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200),
        ]);

        app(QuotaService::class)->syncFromGtk(daysAhead: 3);

        $quotaShift->refresh();

        $this->assertSame(4, $quotaShift->kuota_konsultasi);
        $this->assertSame(1, $quotaShift->kuota_terpakai_konsultasi);
    }

    /**
     * Buffer No-Show (§3.2 PRD) untuk booking KONSULTASI wajib menambah
     * kuota_konsultasi juga, bukan cuma kuota_total - kalau tidak, buffer
     * itu diam-diam "bocor" jadi tambahan kapasitas pemeriksaan (turunan
     * kuota_total - kuota_konsultasi), padahal seharusnya menambah ruang
     * untuk mempromosikan waitlist KONSULTASI.
     */
    public function test_konsultasi_no_show_buffer_grows_konsultasi_allocation_not_just_total(): void
    {
        $this->makeSchedule('pagi');
        $this->makeQuotaShift('pagi', kuotaTotal: 2, kuotaTerpakai: 1, kuotaKonsultasi: 1, kuotaTerpakaiKonsultasi: 1);

        $session = $this->makePatientAndSession('000007');
        $booking = Booking::create([
            'chat_session_id' => $session->id,
            'no_rm' => '000007',
            'kode_poliklinik' => $this->kodePoliklinik,
            'kode_dokter' => $this->kodeDokter,
            'tanggal_periksa' => $this->tanggal,
            'shift' => 'pagi',
            'jenis_layanan' => 'konsultasi',
            'status' => BookingStatus::Booked->value,
        ]);

        app(AntreanService::class)->markNoShow($booking);

        $quota = QuotaShift::where('kode_dokter', $this->kodeDokter)->where('shift', 'pagi')->whereDate('tanggal', $this->tanggal)->first();

        $this->assertGreaterThan(2, $quota->kuota_total);
        $addedBuffer = $quota->kuota_total - 2;
        $this->assertSame(1 + $addedBuffer, $quota->kuota_konsultasi);
    }

    public function test_pemeriksaan_no_show_buffer_grows_total_only_leaving_konsultasi_allocation_unchanged(): void
    {
        $this->makeSchedule('pagi');
        $this->makeQuotaShift('pagi', kuotaTotal: 2, kuotaTerpakai: 1, kuotaKonsultasi: 1, kuotaTerpakaiKonsultasi: 0);

        $session = $this->makePatientAndSession('000008');
        $booking = Booking::create([
            'chat_session_id' => $session->id,
            'no_rm' => '000008',
            'kode_poliklinik' => $this->kodePoliklinik,
            'kode_dokter' => $this->kodeDokter,
            'tanggal_periksa' => $this->tanggal,
            'shift' => 'pagi',
            'jenis_layanan' => 'pemeriksaan',
            'status' => BookingStatus::Booked->value,
        ]);

        app(AntreanService::class)->markNoShow($booking);

        $quota = QuotaShift::where('kode_dokter', $this->kodeDokter)->where('shift', 'pagi')->whereDate('tanggal', $this->tanggal)->first();

        $this->assertGreaterThan(2, $quota->kuota_total);
        $this->assertSame(1, $quota->kuota_konsultasi);
    }
}
