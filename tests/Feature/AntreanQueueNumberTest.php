<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\JenisLayanan;
use App\Enums\Shift;
use App\Jobs\NotifyQueueStatusJob;
use App\Models\Booking;
use App\Models\ChatSession;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Poliklinik;
use App\Models\QuotaShift;
use App\Models\User;
use App\Services\Antrean\AntreanService;
use App\Services\Reminder\KunjunganReminderService;
use App\Services\Whatsapp\WhatsAppServiceInterface;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
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

    /**
     * seedDoctor() sendiri TIDAK membuat DoctorSchedule - cukup untuk test
     * queue-number yang memanipulasi Booking langsung (lihat makeBooking()).
     * Test createBooking()/promoteWaitlist()/moveBookingToShift() di bawah
     * BENAR-BENAR memanggil AntreanService, yang punya gate $jadwalValid
     * sendiri (lihat AntreanService::createBooking()) - WAJIB ada baris
     * DoctorSchedule (source='manual') + QuotaShift nyata dulu.
     */
    protected function seedDoctorWithSchedule(string $kodeDokter = 'D01', string $namaDokter = 'dr. Rina Puspita', ?string $tanggal = null): Doctor
    {
        $doctor = $this->seedDoctor($kodeDokter, $namaDokter);
        $tanggal ??= now()->toDateString();

        $hariMap = [
            'Monday' => 'SENIN', 'Tuesday' => 'SELASA', 'Wednesday' => 'RABU',
            'Thursday' => 'KAMIS', 'Friday' => 'JUMAT', 'Saturday' => 'SABTU', 'Sunday' => 'MINGGU',
        ];

        DoctorSchedule::create([
            'kode_dokter' => $kodeDokter,
            'kode_poliklinik' => '01',
            'hari' => $hariMap[Carbon::parse($tanggal)->format('l')],
            'jam_mulai' => '08:00',
            'jam_selesai' => '12:00',
            'shift' => 'pagi',
            'kuota_total' => 5,
            'source' => 'manual',
        ]);

        QuotaShift::create([
            'kode_dokter' => $kodeDokter,
            'kode_poliklinik' => '01',
            'tanggal' => $tanggal,
            'shift' => 'pagi',
            'kuota_total' => 5,
            'kuota_terpakai' => 0,
        ]);

        return $doctor;
    }

    protected function seedChatSessionWithPatient(string $noRm = '000501'): ChatSession
    {
        Patient::create([
            'no_rm' => $noRm,
            'nama' => 'Budi',
            'jk' => 'LAKI-LAKI',
            'tanggal_lahir' => '2021-01-01',
            'nama_ibu_kandung' => 'Sari',
            'no_hp' => '081234567890',
            'last_synced_at' => now(),
        ]);

        return ChatSession::create([
            'chat_id' => '6281234567890@c.us',
            'state' => 'STATE_1_PENGUMPULAN_DATA',
            'no_rm' => $noRm,
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

    /**
     * Kedatangan pasien BARU (no_antrean sebelumnya null) WAJIB memicu
     * NotifyQueueStatusJob sekali dengan isArrivalConfirmation=true -
     * lihat AntreanService::confirmArrival().
     */
    public function test_confirm_arrival_dispatches_queue_status_job_for_new_arrival(): void
    {
        Bus::fake();
        $this->seedDoctor();

        $booking = $this->makeBooking();
        app(AntreanService::class)->confirmArrival($booking);

        Bus::assertDispatched(
            NotifyQueueStatusJob::class,
            fn (NotifyQueueStatusJob $job) => $job->bookingId === $booking->id && $job->isArrivalConfirmation === true,
        );
        Bus::assertDispatchedTimes(NotifyQueueStatusJob::class, 1);
    }

    /**
     * Panggilan ULANG confirmArrival() ke booking yang no_antrean-nya sudah
     * terisi (bukan kedatangan baru) TIDAK boleh mengirim WA lagi - lihat
     * guard $isNewArrival di AntreanService::confirmArrival().
     */
    public function test_confirm_arrival_does_not_redispatch_for_already_arrived_booking(): void
    {
        $this->seedDoctor();

        $booking = $this->makeBooking();
        app(AntreanService::class)->confirmArrival($booking);

        Bus::fake();
        app(AntreanService::class)->confirmArrival($booking->fresh());

        Bus::assertNotDispatched(NotifyQueueStatusJob::class);
    }

    /**
     * completeVisit() memindahkan status ke Selesai, DAN mengirim
     * NotifyQueueStatusJob (staggered) ke SEMUA booking lain yang masih
     * Arrived di dokter+tanggal+shift yang SAMA - tapi TIDAK ke booking
     * dokter/shift/tanggal lain, dan TIDAK ke dirinya sendiri.
     */
    public function test_complete_visit_marks_selesai_and_notifies_remaining_queue_only(): void
    {
        $this->seedDoctor('D01', 'dr. Rina Puspita');
        $this->seedDoctor('D02', 'dr. Doni Saputra');

        $antrean = app(AntreanService::class);

        $completing = $this->makeBooking(['kode_dokter' => 'D01']);
        $stillWaitingOne = $this->makeBooking(['kode_dokter' => 'D01']);
        $stillWaitingTwo = $this->makeBooking(['kode_dokter' => 'D01']);
        $differentShift = $this->makeBooking(['kode_dokter' => 'D01', 'shift' => Shift::Sore->value]);
        $differentDoctor = $this->makeBooking(['kode_dokter' => 'D02']);

        $antrean->confirmArrival($completing);
        $antrean->confirmArrival($stillWaitingOne);
        $antrean->confirmArrival($stillWaitingTwo);
        $antrean->confirmArrival($differentShift);
        $antrean->confirmArrival($differentDoctor);

        Bus::fake();
        $antrean->completeVisit($completing->fresh());

        $this->assertSame(BookingStatus::Selesai, $completing->fresh()->status);

        Bus::assertDispatchedTimes(NotifyQueueStatusJob::class, 2);
        Bus::assertDispatched(
            NotifyQueueStatusJob::class,
            fn (NotifyQueueStatusJob $job) => $job->bookingId === $stillWaitingOne->id
                && $job->isArrivalConfirmation === false
                && $job->delay !== null,
        );
        Bus::assertDispatched(
            NotifyQueueStatusJob::class,
            fn (NotifyQueueStatusJob $job) => $job->bookingId === $stillWaitingTwo->id
                && $job->isArrivalConfirmation === false
                && $job->delay !== null,
        );
        Bus::assertNotDispatched(
            NotifyQueueStatusJob::class,
            fn (NotifyQueueStatusJob $job) => in_array($job->bookingId, [$completing->id, $differentShift->id, $differentDoctor->id], true),
        );
    }

    /**
     * completeVisit() dipanggil ulang pada booking yang SUDAH Selesai tidak
     * boleh melakukan apa-apa lagi (no-op idempoten, sama seperti pola
     * lain di AntreanService) - termasuk tidak mengirim notifikasi lagi.
     */
    public function test_complete_visit_is_idempotent(): void
    {
        $this->seedDoctor();

        $antrean = app(AntreanService::class);
        $booking = $this->makeBooking();
        $antrean->confirmArrival($booking);
        $antrean->completeVisit($booking->fresh());

        Bus::fake();
        $antrean->completeVisit($booking->fresh());

        Bus::assertNotDispatched(NotifyQueueStatusJob::class);
    }

    /**
     * Aritmetika buildQueueStatusMessage() - contoh dari user: sedang
     * dilayani #3, pasien ini #5, sisa = 1 (cuma #4 yang harus selesai
     * dulu). Juga cek kasus pasien sendiri yang sedang dilayani (sisa=0,
     * "giliran Anda sekarang") dan kasus sendirian di antrean (fallback).
     *
     * Regresi bug nyata dari testing WA: pasien nomor 2 sempat dapat
     * "Giliran Anda sekarang!" padahal nomor 1 masih Arrived (BELUM
     * ditandai Selesai sama sekali) - lihat kasus $middle di bawah, WAJIB
     * dapat pesan "Anda antrean berikutnya", BUKAN "Giliran Anda sekarang".
     */
    public function test_build_queue_status_message_arithmetic(): void
    {
        $this->seedDoctor();
        $antrean = app(AntreanService::class);

        // Sendirian di antrean - fallback currentlyServing ke nomornya
        // sendiri, sisa harus 0 ("giliran Anda sekarang").
        $alone = $this->makeBooking();
        $antrean->confirmArrival($alone);
        $aloneMessage = $antrean->buildQueueStatusMessage($alone->fresh(), true);
        $this->assertStringContainsString('Giliran Anda sekarang', $aloneMessage);

        // Tiga booking lagi datang berurutan - alone jadi #1 (sudah), lalu
        // #2, #3 (sedang dilayani karena #1 di atas belum dites selesai -
        // gunakan dokter LAIN supaya scope-nya bersih dari test sebelumnya).
        $this->seedDoctor('D02', 'dr. Doni Saputra');

        $servingNow = $this->makeBooking(['kode_dokter' => 'D02']); // akan jadi #1 (sedang dilayani)
        $antrean->confirmArrival($servingNow);

        $middle = $this->makeBooking(['kode_dokter' => 'D02']); // #2
        $antrean->confirmArrival($middle);

        // Bug nyata: nomor 1 (servingNow) BELUM Selesai sama sekali di
        // titik ini - nomor 2 (middle) HANYA boleh dianggap "berikutnya",
        // BUKAN "giliran sekarang" (dulu keliru karena formula lama cuma
        // cek "sisa <= 0", padahal sisa=0 juga true untuk kasus ini).
        $middleMessage = $antrean->buildQueueStatusMessage($middle->fresh(), true);
        $this->assertStringNotContainsString('Giliran Anda sekarang', $middleMessage);
        $this->assertStringContainsString('Anda antrean berikutnya', $middleMessage);
        $this->assertStringContainsString('Sedang Dilayani: Nomor *1*', $middleMessage);

        $targetPatient = $this->makeBooking(['kode_dokter' => 'D02']); // #3
        $antrean->confirmArrival($targetPatient);

        // currentlyServing tetap #1 (servingNow) karena belum ada yang
        // Selesai - targetPatient #3, sisa = 3 - 1 - 1 = 1.
        $message = $antrean->buildQueueStatusMessage($targetPatient->fresh(), false);
        $this->assertStringContainsString('Sedang Dilayani: Nomor *1*', $message);
        $this->assertStringContainsString('Tinggal *1* antrean lagi', $message);

        // servingNow sendiri (dia yang sedang dilayani) - sisa harus 0.
        $servingMessage = $antrean->buildQueueStatusMessage($servingNow->fresh(), false);
        $this->assertStringContainsString('Giliran Anda sekarang', $servingMessage);
    }

    protected function fakeGtkBookingEndpoints(): void
    {
        Http::fake([
            '*url=auth*' => Http::response(['response' => ['token' => 'test-token'], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200),
            '*url=regpasien*' => Http::response(['response' => ['no_rawat' => '2026/08/17/'.random_int(1000, 9999), 'no_reg' => '1'], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200),
            '*url=batalkunjungan*' => Http::response(['response' => [], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200),
        ]);
    }

    /**
     * kunjungan_reminder TIDAK lagi disinkron dari GTK (lihat routes/
     * console.php & KunjunganReminderService) - sebagai gantinya,
     * createBooking() WAJIB memanggil upsertForBooking() begitu booking
     * benar-benar dapat no_rawat (status Booked), TIDAK untuk booking yang
     * jatuh ke waitlist (belum ada no_rawat/kepastian jadwal).
     */
    public function test_create_booking_upserts_reminder_only_when_booked_not_waitlisted(): void
    {
        $this->seedDoctorWithSchedule();
        $session = $this->seedChatSessionWithPatient();
        $this->fakeGtkBookingEndpoints();

        $this->mock(KunjunganReminderService::class, function ($mock) {
            $mock->shouldReceive('upsertForBooking')
                ->once()
                ->withArgs(fn (Booking $b) => $b->status === BookingStatus::Booked && filled($b->no_rawat));
        });

        $booking = app(AntreanService::class)->createBooking($session, [
            'no_rm' => '000501',
            'kode_poliklinik' => '01',
            'kode_dokter' => 'D01',
            'tanggal_periksa' => now()->toDateString(),
            'shift' => Shift::Pagi,
            'jenis_layanan' => JenisLayanan::Pemeriksaan,
        ]);

        $this->assertSame(BookingStatus::Booked, $booking->status);
    }

    public function test_create_booking_does_not_upsert_reminder_when_waitlisted(): void
    {
        $doctor = $this->seedDoctorWithSchedule();
        $session = $this->seedChatSessionWithPatient();
        $this->fakeGtkBookingEndpoints();

        // Kuota penuh - reserveSlot() gagal dipenuhi hasAvailability() jadi
        // booking ini WAJIB jatuh ke waitlist, bukan Booked.
        QuotaShift::where('kode_dokter', $doctor->kode_dokter)->update(['kuota_terpakai' => 5]);

        $this->mock(KunjunganReminderService::class, function ($mock) {
            $mock->shouldNotReceive('upsertForBooking');
        });

        $booking = app(AntreanService::class)->createBooking($session, [
            'no_rm' => '000501',
            'kode_poliklinik' => '01',
            'kode_dokter' => 'D01',
            'tanggal_periksa' => now()->toDateString(),
            'shift' => Shift::Pagi,
            'jenis_layanan' => JenisLayanan::Pemeriksaan,
        ]);

        $this->assertSame(BookingStatus::Waitlist, $booking->status);
    }

    /**
     * promoteWaitlist() memindahkan booking waitlist ke Booked (dapat
     * no_rawat) - titik ini WAJIB juga memicu upsertForBooking(), persis
     * seperti createBooking() untuk booking yang langsung Booked.
     */
    public function test_promote_waitlist_upserts_reminder_for_promoted_booking(): void
    {
        $this->seedDoctorWithSchedule();
        $this->fakeGtkBookingEndpoints();

        $waitlisted = $this->makeBooking([
            'status' => BookingStatus::Waitlist->value,
            'waitlist_position' => 1,
        ]);

        $this->mock(KunjunganReminderService::class, function ($mock) use ($waitlisted) {
            $mock->shouldReceive('upsertForBooking')
                ->once()
                ->withArgs(fn (Booking $b) => $b->id === $waitlisted->id);
        });

        app(AntreanService::class)->promoteWaitlist(
            'D01', now()->toDateString(), Shift::Pagi, JenisLayanan::Pemeriksaan, 1,
        );

        $this->assertSame(BookingStatus::Booked, $waitlisted->fresh()->status);
    }

    /**
     * cancelBooking()/markNoShow() WAJIB memanggil cancelForBooking() -
     * tanpa sync GTK lagi, ini satu-satunya cara kunjungan_reminder tahu
     * kunjungannya sudah tidak berlaku (lihat keputusan produk di plan).
     */
    public function test_cancel_booking_cancels_reminder(): void
    {
        $this->seedDoctorWithSchedule();
        $this->fakeGtkBookingEndpoints();

        $booking = $this->makeBooking(['no_rawat' => '2026/08/17/0001']);

        $this->mock(KunjunganReminderService::class, function ($mock) use ($booking) {
            $mock->shouldReceive('cancelForBooking')
                ->once()
                ->withArgs(fn (Booking $b) => $b->id === $booking->id);
        });

        app(AntreanService::class)->cancelBooking($booking);
    }

    public function test_mark_no_show_cancels_reminder(): void
    {
        $this->seedDoctorWithSchedule();
        $this->fakeGtkBookingEndpoints();

        $booking = $this->makeBooking(['no_rawat' => '2026/08/17/0002']);

        $this->mock(KunjunganReminderService::class, function ($mock) use ($booking) {
            $mock->shouldReceive('cancelForBooking')
                ->once()
                ->withArgs(fn (Booking $b) => $b->id === $booking->id);
        });

        app(AntreanService::class)->markNoShow($booking);
    }

    /**
     * cancelShiftAndReschedule() -> moveBookingToShift() memindahkan
     * booking ke shift lain (no_rawat BARU) - kunjungan LAMA harus
     * dibatalkan reminder-nya, kunjungan BARU harus dapat reminder baru
     * (dengan jam_mulai shift baru, bukan shift lama).
     */
    public function test_reschedule_cancels_old_reminder_and_creates_new_one(): void
    {
        $doctor = $this->seedDoctorWithSchedule();

        $hariMap = [
            'Monday' => 'SENIN', 'Tuesday' => 'SELASA', 'Wednesday' => 'RABU',
            'Thursday' => 'KAMIS', 'Friday' => 'JUMAT', 'Saturday' => 'SABTU', 'Sunday' => 'MINGGU',
        ];

        // Shift sore juga perlu jadwal supaya ada tujuan pindah.
        DoctorSchedule::create([
            'kode_dokter' => $doctor->kode_dokter,
            'kode_poliklinik' => '01',
            'hari' => $hariMap[now()->format('l')],
            'jam_mulai' => '15:30',
            'jam_selesai' => '17:00',
            'shift' => 'sore',
            'kuota_total' => 5,
            'source' => 'manual',
        ]);

        $this->fakeGtkBookingEndpoints();

        $original = $this->makeBooking([
            'no_rawat' => '2026/08/17/0003',
            'status' => BookingStatus::Booked->value,
        ]);

        $this->mock(KunjunganReminderService::class, function ($mock) use ($original) {
            $mock->shouldReceive('cancelForBooking')
                ->once()
                ->withArgs(fn (Booking $b) => $b->id === $original->id);
            $mock->shouldReceive('upsertForBooking')
                ->once()
                ->withArgs(fn (Booking $b) => $b->id !== $original->id && $b->shift === Shift::Sore);
        });

        app(AntreanService::class)->cancelShiftAndReschedule('D01', now()->toDateString(), Shift::Pagi);

        $this->assertSame(BookingStatus::Rescheduled, $original->fresh()->status);
    }

    /**
     * Kejadian nyata: WahaWhatsAppService::sendText() gagal (mis. sesi WA
     * putus/WAHA restart sesaat) hanya di-log, TIDAK melempar exception -
     * kalau job ini tidak memeriksa nilai baliknya, pesan posisi antrean
     * hilang tanpa jejak & TIDAK PERNAH di-retry queue. NotifyQueueStatusJob
     * WAJIB melempar exception saat sendText() mengembalikan false, supaya
     * worker (--tries=3) otomatis mencoba lagi.
     */
    public function test_notify_queue_status_job_throws_when_send_fails(): void
    {
        $this->seedDoctor();
        $session = $this->seedChatSessionWithPatient();
        $booking = $this->makeBooking([
            'chat_session_id' => $session->id,
            'status' => BookingStatus::Arrived->value,
            'no_antrean' => 1,
        ]);

        $failingWa = new class implements WhatsAppServiceInterface
        {
            public function sendText(string $to, string $message): bool
            {
                return false;
            }

            public function sendSeen(string $chatId): void {}

            public function startTyping(string $chatId): void {}

            public function stopTyping(string $chatId): void {}
        };

        $job = new NotifyQueueStatusJob($booking->id, true);

        $this->expectException(\RuntimeException::class);
        $job->handle($failingWa, app(AntreanService::class));
    }

    /**
     * Kebalikannya - kalau sendText() berhasil (true), job WAJIB tidak
     * melempar apa pun (jangan sampai fix di atas jadi terlalu agresif dan
     * melempar exception padahal pengiriman sukses).
     */
    public function test_notify_queue_status_job_does_not_throw_when_send_succeeds(): void
    {
        $this->seedDoctor();
        $session = $this->seedChatSessionWithPatient();
        $booking = $this->makeBooking([
            'chat_session_id' => $session->id,
            'status' => BookingStatus::Arrived->value,
            'no_antrean' => 1,
        ]);

        $succeedingWa = new class implements WhatsAppServiceInterface
        {
            public function sendText(string $to, string $message): bool
            {
                return true;
            }

            public function sendSeen(string $chatId): void {}

            public function startTyping(string $chatId): void {}

            public function stopTyping(string $chatId): void {}
        };

        $job = new NotifyQueueStatusJob($booking->id, true);
        $job->handle($succeedingWa, app(AntreanService::class));

        $this->assertTrue(true);
    }
}
