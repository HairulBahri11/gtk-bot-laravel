<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\Shift;
use App\Jobs\NotifyShiftChangeJob;
use App\Models\Booking;
use App\Models\ChatSession;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Poliklinik;
use App\Models\QuotaShift;
use App\Models\ShiftNotificationLog;
use App\Services\Antrean\AntreanService;
use App\Services\Whatsapp\WhatsAppServiceInterface;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AntreanService::cancelShiftAndReschedule()/delayShiftAndNotify() - revisi
 * jadwal dinamis dokter (poin 2/5/6). QUEUE_CONNECTION=sync di phpunit.xml
 * berarti NotifyShiftChangeJob berjalan sinkron begitu di-dispatch (delay()
 * diabaikan driver sync), jadi efeknya (WA terkirim, ShiftNotificationLog)
 * bisa langsung diperiksa tanpa Queue::fake().
 */
class DoctorShiftCancellationTest extends TestCase
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
            '*url=regpasien*' => Http::sequence()
                ->push(['response' => ['no_rawat' => 'RAWAT-A', 'no_reg' => '1'], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200)
                ->push(['response' => ['no_rawat' => 'RAWAT-B', 'no_reg' => '1'], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200)
                ->whenEmpty(Http::response(['response' => ['no_rawat' => 'RAWAT-X', 'no_reg' => '1'], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200)),
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

    protected function makeSchedule(string $shift, string $jamMulai, string $jamSelesai): void
    {
        DoctorSchedule::create([
            'kode_dokter' => $this->kodeDokter,
            'kode_poliklinik' => $this->kodePoliklinik,
            'hari' => $this->hariFor($this->tanggal),
            'jam_mulai' => $jamMulai,
            'jam_selesai' => $jamSelesai,
            'shift' => $shift,
            'kuota_total' => 5,
            'source' => 'manual',
        ]);
    }

    protected function makeQuotaShift(
        string $shift,
        int $kuotaTotal = 5,
        int $kuotaTerpakai = 0,
        string $status = 'open',
        int $kuotaKonsultasi = 1,
        int $kuotaTerpakaiKonsultasi = 0,
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
            'status' => $status,
        ]);
    }

    protected function makeBooking(string $noRm, string $shift, string $jenisLayanan = 'pemeriksaan'): Booking
    {
        Patient::create([
            'no_rm' => $noRm,
            'nama' => "Pasien {$noRm}",
            'jk' => 'LAKI-LAKI',
            'tanggal_lahir' => '2020-01-01',
            'nama_ibu_kandung' => 'Ibu',
            'no_hp' => '081234567890',
        ]);

        $session = ChatSession::create([
            'chat_id' => "628{$noRm}@c.us",
            'state' => 'STATE_3_DONE',
            'context' => [],
            'no_rm' => $noRm,
        ]);

        return Booking::create([
            'chat_session_id' => $session->id,
            'no_rm' => $noRm,
            'no_rawat' => "RAWAT-{$noRm}",
            'kode_poliklinik' => $this->kodePoliklinik,
            'kode_dokter' => $this->kodeDokter,
            'tanggal_periksa' => $this->tanggal,
            'shift' => $shift,
            'jenis_layanan' => $jenisLayanan,
            'status' => BookingStatus::Booked->value,
        ]);
    }

    public function test_cancelling_shift_reschedules_affected_bookings_to_next_open_shift_same_day(): void
    {
        $this->makeSchedule('pagi', '08:00', '09:30');
        $this->makeSchedule('sore', '15:30', '17:00');
        $this->makeQuotaShift('pagi');
        $this->makeQuotaShift('sore');

        $bookingA = $this->makeBooking('000001', 'pagi');
        $bookingB = $this->makeBooking('000002', 'pagi');

        $fakeWa = new FakeShiftWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        app(AntreanService::class)->cancelShiftAndReschedule($this->kodeDokter, $this->tanggal, Shift::Pagi, 'Dokter cuti');

        $bookingA->refresh();
        $bookingB->refresh();

        $this->assertSame(BookingStatus::Rescheduled, $bookingA->status);
        $this->assertNotNull($bookingA->rescheduled_to_booking_id);

        $newBookingA = Booking::find($bookingA->rescheduled_to_booking_id);
        $this->assertSame(Shift::Sore, $newBookingA->shift);
        $this->assertSame(BookingStatus::Booked, $newBookingA->status);
        $this->assertSame($this->tanggal, $newBookingA->tanggal_periksa->toDateString());

        $pagiQuota = QuotaShift::where('kode_dokter', $this->kodeDokter)->where('shift', 'pagi')->whereDate('tanggal', $this->tanggal)->first();
        $this->assertSame('cancelled', $pagiQuota->status);

        $soreQuota = QuotaShift::where('kode_dokter', $this->kodeDokter)->where('shift', 'sore')->whereDate('tanggal', $this->tanggal)->first();
        $this->assertSame(2, $soreQuota->kuota_terpakai);

        $this->assertSame(2, ShiftNotificationLog::where('event', 'rescheduled')->count());
        $this->assertCount(2, $fakeWa->sent);
    }

    public function test_cascade_skips_cancelled_shift_and_moves_to_the_one_after(): void
    {
        $this->makeSchedule('pagi', '08:00', '09:30');
        $this->makeSchedule('sore', '15:30', '17:00');
        $this->makeSchedule('malam', '18:30', '20:00');
        $this->makeQuotaShift('pagi');
        $this->makeQuotaShift('sore', status: 'cancelled');
        $this->makeQuotaShift('malam');

        $booking = $this->makeBooking('000003', 'pagi');

        $fakeWa = new FakeShiftWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        app(AntreanService::class)->cancelShiftAndReschedule($this->kodeDokter, $this->tanggal, Shift::Pagi, 'Dokter cuti');

        $booking->refresh();
        $this->assertSame(BookingStatus::Rescheduled, $booking->status);

        $newBooking = Booking::find($booking->rescheduled_to_booking_id);
        $this->assertSame(Shift::Malam, $newBooking->shift);
    }

    public function test_cancelling_last_shift_of_the_day_leaves_booking_untouched_and_notifies_alternatives(): void
    {
        $this->makeSchedule('malam', '18:30', '20:00');
        $this->makeQuotaShift('malam');

        $booking = $this->makeBooking('000004', 'malam');

        $fakeWa = new FakeShiftWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        app(AntreanService::class)->cancelShiftAndReschedule($this->kodeDokter, $this->tanggal, Shift::Malam, 'Dokter cuti');

        $booking->refresh();
        $this->assertSame(BookingStatus::Booked, $booking->status);
        $this->assertNull($booking->rescheduled_to_booking_id);

        $this->assertSame(1, ShiftNotificationLog::where('event', 'cancelled_no_alternative')->count());
        $this->assertCount(1, $fakeWa->sent);
    }

    public function test_delaying_shift_does_not_move_bookings_but_notifies_patients(): void
    {
        $this->makeSchedule('sore', '15:30', '17:00');
        $this->makeQuotaShift('sore');

        $booking = $this->makeBooking('000005', 'sore');

        $fakeWa = new FakeShiftWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        app(AntreanService::class)->delayShiftAndNotify($this->kodeDokter, $this->tanggal, Shift::Sore, 30, 'Dokter terlambat');

        $booking->refresh();
        $this->assertSame(BookingStatus::Booked, $booking->status);
        $this->assertSame('sore', $booking->shift->value);

        $quota = QuotaShift::where('kode_dokter', $this->kodeDokter)->where('shift', 'sore')->whereDate('tanggal', $this->tanggal)->first();
        $this->assertSame('delayed', $quota->status);
        $this->assertSame(30, $quota->delay_minutes);

        $this->assertCount(1, $fakeWa->sent);
        $this->assertStringContainsString('30 menit', $fakeWa->sent[0]['message']);
    }

    public function test_notify_shift_change_job_is_idempotent_per_booking_and_event(): void
    {
        $this->makeSchedule('pagi', '08:00', '09:30');
        $booking = $this->makeBooking('000006', 'pagi');

        $fakeWa = new FakeShiftWhatsAppService;

        $job = new NotifyShiftChangeJob($booking->id, 'delayed', ['tanggal' => $this->tanggal, 'delay_minutes' => 15]);
        $job->handle($fakeWa);
        $job->handle($fakeWa);

        $this->assertCount(1, $fakeWa->sent);
        $this->assertSame(1, ShiftNotificationLog::where('booking_id', $booking->id)->where('event', 'delayed')->count());
    }
}

class FakeShiftWhatsAppService implements WhatsAppServiceInterface
{
    /** @var array<int, array{to: string, message: string}> */
    public array $sent = [];

    public function sendText(string $to, string $message): void
    {
        $this->sent[] = ['to' => $to, 'message' => $message];
    }

    public function sendSeen(string $chatId): void {}

    public function startTyping(string $chatId): void {}

    public function stopTyping(string $chatId): void {}
}
