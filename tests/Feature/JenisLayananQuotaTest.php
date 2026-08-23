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
 * Setiap shift membagi kuota_total jadi TIGA pool TERISOLASI - Pemeriksaan
 * (Periksa Sakit/Imunisasi digabung), Konsultasi Gizi, dan Konsultasi Tumbuh
 * Kembang (lihat QuotaShift::tersisaFor()) - bukan cuma angka-angka
 * dekoratif. Ini akar dari seluruh fitur pemisahan kuota, jadi diuji
 * terpisah dari DoctorShiftCancellationTest.php yang fokus ke cancel/delay
 * shift. Kasus-kasus di sini sengaja memakai Tumbuh Kembang sebagai pool
 * "lainnya" (bukan Gizi) - cukup untuk membuktikan isolasi antar pool,
 * tidak perlu menguji ketiganya sekaligus di tiap kasus.
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

    protected function makeSchedule(string $shift, int $kuotaTotal = 15, int $kuotaKonsultasiTumbuhKembang = 1): void
    {
        DoctorSchedule::create([
            'kode_dokter' => $this->kodeDokter,
            'kode_poliklinik' => $this->kodePoliklinik,
            'hari' => $this->hariFor($this->tanggal),
            'jam_mulai' => '08:00',
            'jam_selesai' => '09:30',
            'shift' => $shift,
            'kuota_total' => $kuotaTotal,
            'kuota_konsultasi_gizi' => 0,
            'kuota_konsultasi_tumbuh_kembang' => $kuotaKonsultasiTumbuhKembang,
            'source' => 'manual',
        ]);
    }

    protected function makeQuotaShift(
        string $shift,
        int $kuotaTotal,
        int $kuotaTerpakai,
        int $kuotaKonsultasiTumbuhKembang,
        int $kuotaTerpakaiKonsultasiTumbuhKembang,
    ): QuotaShift {
        return QuotaShift::create([
            'kode_dokter' => $this->kodeDokter,
            'kode_poliklinik' => $this->kodePoliklinik,
            'tanggal' => $this->tanggal,
            'shift' => $shift,
            'kuota_total' => $kuotaTotal,
            'kuota_terpakai' => $kuotaTerpakai,
            'kuota_konsultasi_gizi' => 0,
            'kuota_terpakai_konsultasi_gizi' => 0,
            'kuota_konsultasi_tumbuh_kembang' => $kuotaKonsultasiTumbuhKembang,
            'kuota_terpakai_konsultasi_tumbuh_kembang' => $kuotaTerpakaiKonsultasiTumbuhKembang,
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
        // Total 2: konsultasi tumbuh kembang (1) penuh terpakai, pemeriksaan
        // (1) masih kosong.
        $this->makeQuotaShift('pagi', kuotaTotal: 2, kuotaTerpakai: 1, kuotaKonsultasiTumbuhKembang: 1, kuotaTerpakaiKonsultasiTumbuhKembang: 1);

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
        // Total 2: konsultasi tumbuh kembang (1) masih kosong, pemeriksaan
        // (1) penuh terpakai.
        $this->makeQuotaShift('pagi', kuotaTotal: 2, kuotaTerpakai: 1, kuotaKonsultasiTumbuhKembang: 1, kuotaTerpakaiKonsultasiTumbuhKembang: 0);

        $session = $this->makePatientAndSession('000002');

        $booking = app(AntreanService::class)->createBooking($session, [
            'no_rm' => '000002',
            'kode_poliklinik' => $this->kodePoliklinik,
            'kode_dokter' => $this->kodeDokter,
            'tanggal_periksa' => $this->tanggal,
            'shift' => Shift::Pagi,
            'jenis_layanan' => JenisLayanan::KonsultasiTumbuhKembang,
        ]);

        $this->assertSame(BookingStatus::Booked, $booking->status);
    }

    public function test_booking_waitlisted_when_own_category_full_even_if_other_category_has_room(): void
    {
        $this->makeSchedule('pagi');
        // konsultasi tumbuh kembang (1) penuh, pemeriksaan (1) masih kosong
        // - tapi pasien ini minta KONSULTASI TUMBUH KEMBANG, jadi tetap
        // wajib waitlist.
        $this->makeQuotaShift('pagi', kuotaTotal: 2, kuotaTerpakai: 1, kuotaKonsultasiTumbuhKembang: 1, kuotaTerpakaiKonsultasiTumbuhKembang: 1);

        $session = $this->makePatientAndSession('000003');

        $booking = app(AntreanService::class)->createBooking($session, [
            'no_rm' => '000003',
            'kode_poliklinik' => $this->kodePoliklinik,
            'kode_dokter' => $this->kodeDokter,
            'tanggal_periksa' => $this->tanggal,
            'shift' => Shift::Pagi,
            'jenis_layanan' => JenisLayanan::KonsultasiTumbuhKembang,
        ]);

        $this->assertSame(BookingStatus::Waitlist, $booking->status);
        $this->assertSame(1, $booking->waitlist_position);
    }

    public function test_waitlist_promotion_only_promotes_matching_category(): void
    {
        $this->makeSchedule('pagi');
        $this->makeQuotaShift('pagi', kuotaTotal: 2, kuotaTerpakai: 0, kuotaKonsultasiTumbuhKembang: 1, kuotaTerpakaiKonsultasiTumbuhKembang: 0);

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
            'jenis_layanan' => 'konsultasi_tumbuh_kembang',
            'status' => BookingStatus::Waitlist->value,
            'waitlist_position' => 1,
        ]);

        // Satu slot PEMERIKSAAN terbuka (mis. batal) - promosikan HANYA
        // waitlist kategori pemeriksaan, waitlist konsultasi tumbuh kembang
        // tidak disentuh walau posisinya sama-sama #1.
        app(AntreanService::class)->promoteWaitlist($this->kodeDokter, $this->tanggal, Shift::Pagi, JenisLayanan::Pemeriksaan, 1);

        $bookingPemeriksaan->refresh();
        $bookingKonsultasi->refresh();

        $this->assertSame(BookingStatus::Booked, $bookingPemeriksaan->status);
        $this->assertSame(BookingStatus::Waitlist, $bookingKonsultasi->status);
    }

    /**
     * Regresi paling penting dari fitur ini: kuota_konsultasi_tumbuh_kembang
     * adalah ALOKASI yang admin atur manual dari dashboard - rebuild berkala
     * (quota:rebuild-shifts, tiap 10 menit) TIDAK BOLEH menimpanya balik ke
     * default template, sama seperti status/delay_minutes/reason yang
     * sudah dilindungi lebih dulu. kuota_terpakai_konsultasi_tumbuh_kembang
     * sebaliknya WAJIB direkomputasi tiap rebuild (fakta terpakai, bukan
     * target admin).
     */
    public function test_sync_protects_manual_kuota_konsultasi_but_recomputes_usage(): void
    {
        $this->makeSchedule('pagi', kuotaTotal: 15, kuotaKonsultasiTumbuhKembang: 1);
        $quotaShift = $this->makeQuotaShift('pagi', kuotaTotal: 15, kuotaTerpakai: 0, kuotaKonsultasiTumbuhKembang: 1, kuotaTerpakaiKonsultasiTumbuhKembang: 0);

        // Admin menaikkan alokasi konsultasi tumbuh kembang HARI INI secara
        // manual dari dashboard (mis. kuota pemeriksaan sepi).
        $quotaShift->update(['kuota_konsultasi_tumbuh_kembang' => 4]);

        $sessionKonsultasi = $this->makePatientAndSession('000006');
        Booking::create([
            'chat_session_id' => $sessionKonsultasi->id,
            'no_rm' => '000006',
            'kode_poliklinik' => $this->kodePoliklinik,
            'kode_dokter' => $this->kodeDokter,
            'tanggal_periksa' => $this->tanggal,
            'shift' => 'pagi',
            'jenis_layanan' => 'konsultasi_tumbuh_kembang',
            'status' => BookingStatus::Booked->value,
        ]);

        app(QuotaService::class)->rebuildQuotaShifts(3);

        $quotaShift->refresh();

        $this->assertSame(4, $quotaShift->kuota_konsultasi_tumbuh_kembang);
        $this->assertSame(1, $quotaShift->kuota_terpakai_konsultasi_tumbuh_kembang);
    }

    /**
     * Buffer No-Show (§3.2 PRD) untuk booking KONSULTASI TUMBUH KEMBANG
     * wajib menambah kuota_konsultasi_tumbuh_kembang juga, bukan cuma
     * kuota_total - kalau tidak, buffer itu diam-diam "bocor" jadi tambahan
     * kapasitas pemeriksaan (turunan kuota_total dikurangi kedua alokasi
     * konsultasi), padahal seharusnya menambah ruang untuk mempromosikan
     * waitlist KONSULTASI TUMBUH KEMBANG.
     */
    public function test_konsultasi_no_show_buffer_grows_konsultasi_allocation_not_just_total(): void
    {
        $this->makeSchedule('pagi');
        $this->makeQuotaShift('pagi', kuotaTotal: 2, kuotaTerpakai: 1, kuotaKonsultasiTumbuhKembang: 1, kuotaTerpakaiKonsultasiTumbuhKembang: 1);

        $session = $this->makePatientAndSession('000007');
        $booking = Booking::create([
            'chat_session_id' => $session->id,
            'no_rm' => '000007',
            'kode_poliklinik' => $this->kodePoliklinik,
            'kode_dokter' => $this->kodeDokter,
            'tanggal_periksa' => $this->tanggal,
            'shift' => 'pagi',
            'jenis_layanan' => 'konsultasi_tumbuh_kembang',
            'status' => BookingStatus::Booked->value,
        ]);

        app(AntreanService::class)->markNoShow($booking);

        $quota = QuotaShift::where('kode_dokter', $this->kodeDokter)->where('shift', 'pagi')->whereDate('tanggal', $this->tanggal)->first();

        $this->assertGreaterThan(2, $quota->kuota_total);
        $addedBuffer = $quota->kuota_total - 2;
        $this->assertSame(1 + $addedBuffer, $quota->kuota_konsultasi_tumbuh_kembang);
    }

    public function test_pemeriksaan_no_show_buffer_grows_total_only_leaving_konsultasi_allocation_unchanged(): void
    {
        $this->makeSchedule('pagi');
        $this->makeQuotaShift('pagi', kuotaTotal: 2, kuotaTerpakai: 1, kuotaKonsultasiTumbuhKembang: 1, kuotaTerpakaiKonsultasiTumbuhKembang: 0);

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
        $this->assertSame(1, $quota->kuota_konsultasi_tumbuh_kembang);
    }

    /**
     * Kejadian nyata (bug): QuotaService::suggestAlternatives() dulu
     * memakai limit($limit * 5) di level SQL SEBELUM baris difilter
     * ketersediaan riil di PHP (tersisaFor()) - kalau belasan/puluhan
     * baris pertama (terurut by tanggal) sama-sama tanpa kuota kategori
     * ini secara berturut-turut, baris yang sebenarnya tersedia jauh
     * lebih jauh tidak pernah ikut terambil sama sekali, sehingga
     * alternatif nyata yang ada malah dilaporkan "tidak ada" ke pasien
     * (waitlist tanpa tawaran, "kapan tersedia" jatuh ke AI yang
     * mengarahkan ke admin). Reproduksi persis: 20 hari berturut-turut
     * TANPA kuota Konsultasi Gizi (lebih banyak dari limit lama, 3*5=15),
     * baru hari ke-21 yang benar-benar tersedia.
     */
    public function test_suggest_alternatives_finds_slot_beyond_many_consecutive_full_rows(): void
    {
        $this->makeSchedule('pagi');

        $start = Carbon::parse($this->tanggal);

        for ($i = 0; $i < 20; $i++) {
            QuotaShift::create([
                'kode_dokter' => $this->kodeDokter,
                'kode_poliklinik' => $this->kodePoliklinik,
                'tanggal' => $start->copy()->addDays($i)->toDateString(),
                'shift' => 'pagi',
                'kuota_total' => 5,
                'kuota_terpakai' => 0,
                'kuota_konsultasi_gizi' => 0,
                'kuota_terpakai_konsultasi_gizi' => 0,
                'kuota_konsultasi_tumbuh_kembang' => 0,
                'kuota_terpakai_konsultasi_tumbuh_kembang' => 0,
                'status' => 'open',
            ]);
        }

        $availableDate = $start->copy()->addDays(20)->toDateString();

        QuotaShift::create([
            'kode_dokter' => $this->kodeDokter,
            'kode_poliklinik' => $this->kodePoliklinik,
            'tanggal' => $availableDate,
            'shift' => 'pagi',
            'kuota_total' => 5,
            'kuota_terpakai' => 0,
            'kuota_konsultasi_gizi' => 2,
            'kuota_terpakai_konsultasi_gizi' => 0,
            'kuota_konsultasi_tumbuh_kembang' => 0,
            'kuota_terpakai_konsultasi_tumbuh_kembang' => 0,
            'status' => 'open',
        ]);

        $alternatives = app(QuotaService::class)->suggestAlternatives($this->kodeDokter, $this->tanggal, JenisLayanan::KonsultasiGizi);

        $this->assertNotEmpty($alternatives);
        $this->assertSame($availableDate, $alternatives[0]['tanggal']);
        $this->assertSame('pagi', $alternatives[0]['shift']);
    }
}
