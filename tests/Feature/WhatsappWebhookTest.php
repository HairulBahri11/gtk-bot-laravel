<?php

namespace Tests\Feature;

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
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\WhatsAppServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected string $chatId = '6281234567890@c.us';

    /**
     * Alur end-to-end STATE_1 -> STATE_2/STATE_3 tanpa kredensial
     * WAHA/GTK/OpenRouter asli - seluruh HTTP eksternal di-fake, hanya
     * memverifikasi state machine, pembuatan Patient/ChatSession/Booking.
     * Satu giliran saja - pasien baru didaftarkan, slot "secepatnya"
     * langsung dicari & booking langsung dibuat pada giliran yang sama
     * (resolveSlotAndReply() tidak lagi menahan giliran ekstra untuk
     * konfirmasi slot, lihat komentar di resolveSlotAndReply()).
     */
    public function test_full_registration_and_booking_flow(): void
    {
        $this->seedMasterData();

        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            'openrouter.ai/*' => Http::response($this->openRouterResponse($this->stateOneAiContent()), 200),
            '*url=auth*' => Http::response($this->gtkOk(['token' => 'test-token']), 200),
            '*url=caripasien*' => Http::response($this->gtkFail('Data tidak ditemukan', 404), 200),
            '*url=tambahpasien*' => Http::response($this->gtkOk(['no_rkm_medis' => '000099'], 'Pasien baru berhasil didaftarkan'), 200),
            '*url=regpasien*' => Http::response($this->gtkOk(['no_rawat' => '2026/07/20/000001', 'no_reg' => '1'], 'Registrasi berhasil'), 200),
        ]);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Anak saya Budi, lahir 2021-01-01, ibu Sari, keluhan demam, laki-laki',
            ],
        ])->assertOk();

        $session = ChatSession::query()->where('chat_id', $this->chatId)->firstOrFail();
        $this->assertSame(ChatState::Done, $session->state);
        $this->assertSame('000099', $session->no_rm);

        $booking = Booking::query()->where('no_rm', '000099')->firstOrFail();
        $this->assertSame(BookingStatus::Booked, $booking->status);
        $this->assertSame('2026/07/20/000001', $booking->no_rawat);

        $this->assertCount(1, $fakeWa->sent);
    }

    /**
     * User boleh minta tanggal kunjungan spesifik (bukan cuma "hari ini"/
     * jadwal terdekat) - selama poliklinik memang buka di hari itu & masih
     * ada kuota, booking harus jatuh persis di tanggal yang diminta.
     */
    public function test_booking_uses_requested_date_when_specified(): void
    {
        $this->seedMasterData();

        // Jadwal dokter berulang mingguan (DoctorSchedule) sudah otomatis
        // mencakup tanggal yang sama persis 7 hari ke depan - cukup tambah
        // snapshot kuota (QuotaShift) untuk tanggal itu supaya booking jatuh
        // ke status "Booked", bukan waitlist karena kuota belum tersinkron.
        $requestedDate = now()->addDays(7)->toDateString();

        QuotaShift::create([
            'kode_dokter' => 'D01',
            'kode_poliklinik' => '01',
            'tanggal' => $requestedDate,
            'shift' => 'pagi',
            'kuota_total' => 5,
            'kuota_terpakai' => 0,
        ]);

        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            // Tanggal spesifik yang diminta sekarang bagian dari formulir
            // STATE_1 itu sendiri (jadwal kunjungan) - bukan lagi override di
            // STATE_2 seperti dulu. Karena diminta EKSPLISIT (bukan
            // "secepatnya"), resolveSlotAndReply() langsung booking di
            // giliran ini juga, TANPA giliran konfirmasi slot terpisah lagi
            // (lihat komentar slot_ditawarkan di resolveSlotAndReply()) -
            // jadi cukup satu respons OpenRouter, satu giliran webhook.
            'openrouter.ai/*' => Http::response($this->openRouterResponse($this->stateOneAiContent(['tanggal_kunjungan' => $requestedDate])), 200),
            '*url=auth*' => Http::response($this->gtkOk(['token' => 'test-token']), 200),
            '*url=caripasien*' => Http::response($this->gtkFail('Data tidak ditemukan', 404), 200),
            '*url=tambahpasien*' => Http::response($this->gtkOk(['no_rkm_medis' => '000099'], 'Pasien baru berhasil didaftarkan'), 200),
            '*url=regpasien*' => Http::response($this->gtkOk(['no_rawat' => '2026/07/27/000001', 'no_reg' => '1'], 'Registrasi berhasil'), 200),
        ]);

        // Giliran 1: formulir lengkap dengan tanggal spesifik -> slot untuk
        // tanggal itu langsung dicari & booking langsung dibuat (tidak perlu
        // giliran konfirmasi lagi karena tanggalnya sendiri sudah eksplisit
        // dari pasien).
        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Anak saya Budi, lahir 2021-01-01, ibu Sari, keluhan demam, laki-laki',
            ],
        ])->assertOk();

        $booking = Booking::query()->where('no_rm', '000099')->firstOrFail();
        $this->assertSame(BookingStatus::Booked, $booking->status);
        $this->assertSame($requestedDate, $booking->tanggal_periksa->toDateString());
    }

    /**
     * Seed dokter dengan jadwal Pagi (08:00-09:30) DAN Sore (15:30-17:00)
     * pada hari yang SAMA (dipakai test waktu-nyata di bawah) - beda dari
     * seedMasterData() yang cuma satu shift, di sini sengaja dua supaya
     * bisa menguji perpindahan otomatis Pagi->Sore begitu jam Pagi sudah
     * lewat.
     */
    protected function seedMasterDataWithMultipleShiftsToday(): void
    {
        $poli = Poliklinik::create([
            'kode_poliklinik' => '01',
            'nama_poliklinik' => 'Tumbuh Kembang Anak',
            'is_active' => true,
        ]);

        Doctor::create([
            'kode_dokter' => 'D01',
            'nama_dokter' => 'dr. Rina Puspita',
            'kode_poliklinik' => $poli->kode_poliklinik,
            'is_active' => true,
        ]);

        $todayHari = ['Monday' => 'SENIN', 'Tuesday' => 'SELASA', 'Wednesday' => 'RABU', 'Thursday' => 'KAMIS', 'Friday' => 'JUMAT', 'Saturday' => 'SABTU', 'Sunday' => 'MINGGU'][now()->format('l')];

        foreach ([['pagi', '08:00', '09:30'], ['sore', '15:30', '17:00']] as [$shift, $mulai, $selesai]) {
            DoctorSchedule::create([
                'kode_dokter' => 'D01',
                'kode_poliklinik' => $poli->kode_poliklinik,
                'hari' => $todayHari,
                'jam_mulai' => $mulai,
                'jam_selesai' => $selesai,
                'shift' => $shift,
                'kuota_total' => 5,
                'source' => 'manual',
            ]);

            QuotaShift::create([
                'kode_dokter' => 'D01',
                'kode_poliklinik' => $poli->kode_poliklinik,
                'tanggal' => now()->toDateString(),
                'shift' => $shift,
                'kuota_total' => 5,
                'kuota_terpakai' => 0,
            ]);
        }
    }

    /**
     * Kejadian nyata (bug): pasien chat jam 12:10 minta "hari ini" dengan
     * shift Pagi (08:00-09:30) - sesi itu SUDAH BERAKHIR 2,5 jam sebelumnya,
     * tapi sistem lama tetap mendaftarkan ke sana. Sekarang WAJIB otomatis
     * pindah ke sesi berikutnya hari ini (Sore) yang belum lewat jamnya,
     * DAN pesan sukses WAJIB menjelaskan pergantian sesi ini secara
     * eksplisit ke orang tua.
     */
    public function test_booking_substitutes_next_shift_today_when_requested_shift_already_passed(): void
    {
        $this->travelTo(now()->setTime(12, 10));

        $this->seedMasterDataWithMultipleShiftsToday();

        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            'openrouter.ai/*' => Http::response($this->openRouterResponse($this->stateOneAiContent([
                'tanggal_kunjungan' => now()->toDateString(),
                'shift_pilihan' => 'pagi',
            ])), 200),
            '*url=auth*' => Http::response($this->gtkOk(['token' => 'test-token']), 200),
            '*url=caripasien*' => Http::response($this->gtkFail('Data tidak ditemukan', 404), 200),
            '*url=tambahpasien*' => Http::response($this->gtkOk(['no_rkm_medis' => '000099'], 'Pasien baru berhasil didaftarkan'), 200),
            '*url=regpasien*' => Http::response($this->gtkOk(['no_rawat' => '2026/08/18/000001', 'no_reg' => '1'], 'Registrasi berhasil'), 200),
        ]);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Anak saya Budi, lahir 2021-01-01, ibu Sari, keluhan demam, laki-laki, hari ini yg available',
            ],
        ])->assertOk();

        $booking = Booking::query()->where('no_rm', '000099')->firstOrFail();
        $this->assertSame(BookingStatus::Booked, $booking->status);
        $this->assertSame(Shift::Sore, $booking->shift, 'sesi Pagi sudah lewat, booking wajib jatuh ke Sore');
        $this->assertSame(now()->toDateString(), $booking->tanggal_periksa->toDateString());

        $this->assertStringContainsString('sesi Pagi', $fakeWa->sent[0]['message']);
        $this->assertStringContainsString('sudah berakhir', $fakeWa->sent[0]['message']);
        $this->assertStringContainsString('sesi Sore', $fakeWa->sent[0]['message']);
    }

    /**
     * Kalau SEMUA sesi hari ini sudah lewat jamnya (bukan cuma Pagi), sistem
     * TIDAK BOLEH tetap membuat booking hari ini - harus menolak & menawarkan
     * tanggal berikutnya (mekanisme findUpcomingDatesForShift() yang sudah
     * ada), persis seperti kasus "tidak ada jadwal sama sekali".
     */
    public function test_booking_offers_alternate_date_when_all_shifts_today_already_passed(): void
    {
        $this->travelTo(now()->setTime(22, 0));

        $this->seedMasterDataWithMultipleShiftsToday();

        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            'openrouter.ai/*' => Http::response($this->openRouterResponse($this->stateOneAiContent([
                'tanggal_kunjungan' => now()->toDateString(),
                'shift_pilihan' => 'pagi',
            ])), 200),
            '*url=auth*' => Http::response($this->gtkOk(['token' => 'test-token']), 200),
            '*url=caripasien*' => Http::response($this->gtkFail('Data tidak ditemukan', 404), 200),
            '*url=tambahpasien*' => Http::response($this->gtkOk(['no_rkm_medis' => '000099'], 'Sukses simpan data'), 200),
        ]);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Anak saya Budi, lahir 2021-01-01, ibu Sari, keluhan demam, laki-laki, hari ini yg available',
            ],
        ])->assertOk();

        $this->assertSame(0, Booking::query()->count());
        $this->assertStringContainsString('tidak ada jadwal', $fakeWa->sent[0]['message']);
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'regpasien'));
    }

    /**
     * Kejadian nyata: dua dokter sama-sama praktik di
     * poliklinik+tanggal+shift yang sama, pasien eksplisit menyebutkan
     * nama dokter di jawaban jadwal kunjungan - tapi sistem lama diam-diam
     * memilih dokter LAIN (urutan query semata) mengabaikan permintaan
     * pasien. Sekarang begitu dokter_pilihan diisi & cocok salah satu
     * kandidat, booking WAJIB jatuh ke dokter itu - tanpa giliran
     * konfirmasi tambahan (tanggal+dokter sama-sama eksplisit dari pasien
     * sendiri).
     */
    public function test_booking_uses_requested_doctor_when_multiple_doctors_available(): void
    {
        $this->seedMasterData();

        $requestedDate = now()->addDays(7)->toDateString();

        Doctor::create([
            'kode_dokter' => 'D02',
            'nama_dokter' => 'dr. Doni Saputra',
            'kode_poliklinik' => '01',
            'is_active' => true,
        ]);

        $hariMap = [
            'Monday' => 'SENIN', 'Tuesday' => 'SELASA', 'Wednesday' => 'RABU',
            'Thursday' => 'KAMIS', 'Friday' => 'JUMAT', 'Saturday' => 'SABTU', 'Sunday' => 'MINGGU',
        ];

        DoctorSchedule::create([
            'kode_dokter' => 'D02',
            'kode_poliklinik' => '01',
            'hari' => $hariMap[now()->format('l')],
            'jam_mulai' => '08:00',
            'jam_selesai' => '12:00',
            'shift' => 'pagi',
            'kuota_total' => 5,
            'source' => 'manual',
        ]);

        foreach (['D01', 'D02'] as $kodeDokter) {
            QuotaShift::create([
                'kode_dokter' => $kodeDokter,
                'kode_poliklinik' => '01',
                'tanggal' => $requestedDate,
                'shift' => 'pagi',
                'kuota_total' => 5,
                'kuota_terpakai' => 0,
            ]);
        }

        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            'openrouter.ai/*' => Http::response($this->openRouterResponse($this->stateOneAiContent([
                'tanggal_kunjungan' => $requestedDate,
                'dokter_pilihan' => 'dr. Doni Saputra',
            ])), 200),
            '*url=auth*' => Http::response($this->gtkOk(['token' => 'test-token']), 200),
            '*url=caripasien*' => Http::response($this->gtkFail('Data tidak ditemukan', 404), 200),
            '*url=tambahpasien*' => Http::response($this->gtkOk(['no_rkm_medis' => '000099'], 'Pasien baru berhasil didaftarkan'), 200),
            '*url=regpasien*' => Http::response($this->gtkOk(['no_rawat' => '2026/07/27/000001', 'no_reg' => '1'], 'Registrasi berhasil'), 200),
        ]);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Anak saya Budi, lahir 2021-01-01, ibu Sari, keluhan demam, laki-laki',
            ],
        ])->assertOk();

        $booking = Booking::query()->where('no_rm', '000099')->firstOrFail();
        $this->assertSame(BookingStatus::Booked, $booking->status);
        $this->assertSame('D02', $booking->kode_dokter);
    }

    /**
     * Sama seperti test di atas TAPI pasien TIDAK menyebutkan dokter mana
     * yang diinginkan - sistem WAJIB bertanya dulu (bukan diam-diam
     * memilihkan salah satu), baru booking begitu dokter dipilih di
     * giliran berikutnya.
     */
    public function test_booking_asks_which_doctor_when_multiple_available_then_books_chosen_one(): void
    {
        $this->seedMasterData();

        $requestedDate = now()->addDays(7)->toDateString();

        Doctor::create([
            'kode_dokter' => 'D02',
            'nama_dokter' => 'dr. Doni Saputra',
            'kode_poliklinik' => '01',
            'is_active' => true,
        ]);

        $hariMap = [
            'Monday' => 'SENIN', 'Tuesday' => 'SELASA', 'Wednesday' => 'RABU',
            'Thursday' => 'KAMIS', 'Friday' => 'JUMAT', 'Saturday' => 'SABTU', 'Sunday' => 'MINGGU',
        ];

        DoctorSchedule::create([
            'kode_dokter' => 'D02',
            'kode_poliklinik' => '01',
            'hari' => $hariMap[now()->format('l')],
            'jam_mulai' => '08:00',
            'jam_selesai' => '12:00',
            'shift' => 'pagi',
            'kuota_total' => 5,
            'source' => 'manual',
        ]);

        foreach (['D01', 'D02'] as $kodeDokter) {
            QuotaShift::create([
                'kode_dokter' => $kodeDokter,
                'kode_poliklinik' => '01',
                'tanggal' => $requestedDate,
                'shift' => 'pagi',
                'kuota_total' => 5,
                'kuota_terpakai' => 0,
            ]);
        }

        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push($this->openRouterResponse($this->stateOneAiContent(['tanggal_kunjungan' => $requestedDate])), 200)
                ->push($this->openRouterResponse(json_encode([
                    'reply' => 'Baik, dr. Doni Saputra.',
                    'extracted' => ['dokter_pilihan' => 'dr. Doni Saputra'],
                    'ready_for_next_state' => true,
                ])), 200),
            '*url=auth*' => Http::response($this->gtkOk(['token' => 'test-token']), 200),
            '*url=caripasien*' => Http::response($this->gtkFail('Data tidak ditemukan', 404), 200),
            '*url=tambahpasien*' => Http::response($this->gtkOk(['no_rkm_medis' => '000099'], 'Pasien baru berhasil didaftarkan'), 200),
            '*url=regpasien*' => Http::response($this->gtkOk(['no_rawat' => '2026/07/27/000001', 'no_reg' => '1'], 'Registrasi berhasil'), 200),
        ]);

        // Giliran 1: formulir lengkap TANPA preferensi dokter -> dua dokter
        // tersedia di tanggal/shift yang sama, sistem WAJIB bertanya dulu.
        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Anak saya Budi, lahir 2021-01-01, ibu Sari, keluhan demam, laki-laki',
            ],
        ])->assertOk();

        $this->assertSame(0, Booking::query()->count());
        $this->assertStringContainsString('dr. Rina Puspita', $fakeWa->sent[0]['message']);
        $this->assertStringContainsString('dr. Doni Saputra', $fakeWa->sent[0]['message']);

        // Giliran 2: pasien menyebutkan dokter pilihannya -> booking langsung
        // dibuat untuk dokter itu (tanggal sudah eksplisit dari giliran 1).
        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'dr. Doni Saputra saja',
            ],
        ])->assertOk();

        $booking = Booking::query()->where('no_rm', '000099')->firstOrFail();
        $this->assertSame(BookingStatus::Booked, $booking->status);
        $this->assertSame('D02', $booking->kode_dokter);
    }

    /**
     * Jadwal kunjungan (shift_pilihan + tanggal_kunjungan_dijawab) kini
     * bagian dari formulir STATE_1 itu sendiri (bukan ditanyakan belakangan
     * di STATE_2 lagi) - kalau AI keliru langsung set ready_for_next_state
     * tanpa pernah menandai tanggal_kunjungan_dijawab, server harus menahan
     * sesi di STATE_1 (bukan diam-diam lanjut ke resolusi slot/booking).
     */
    public function test_booking_is_not_created_until_tanggal_kunjungan_is_answered(): void
    {
        $this->seedMasterData();

        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            'openrouter.ai/*' => Http::response($this->openRouterResponse($this->stateOneAiContent([
                // AI keliru: field lain lengkap tapi jadwal kunjungan tidak
                // pernah ditandai - server wajib menahan di STATE_1.
                'shift_pilihan' => null,
                'tanggal_kunjungan' => null,
                'tanggal_kunjungan_dijawab' => null,
            ])), 200),
            '*url=auth*' => Http::response($this->gtkOk(['token' => 'test-token']), 200),
            '*url=caripasien*' => Http::response($this->gtkFail('Data tidak ditemukan', 404), 200),
        ]);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Anak saya Budi, lahir 2021-01-01, ibu Sari, keluhan demam, laki-laki',
            ],
        ])->assertOk();

        $session = ChatSession::query()->where('chat_id', $this->chatId)->firstOrFail();
        $this->assertSame(ChatState::PengumpulanData, $session->state);
        $this->assertNull($session->no_rm);
        $this->assertSame(0, Booking::query()->count());
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'tambahpasien'));
    }

    /**
     * Kejadian nyata: AI menandai tanggal_kunjungan_dijawab = true TAPI lupa
     * mengisi tanggal_kunjungan itu sendiri di giliran yang sama - server
     * harus tetap menahan di STATE_1 (bukan diam-diam menebak
     * "secepatnya"/tanggal lain).
     */
    public function test_booking_is_not_created_when_tanggal_kunjungan_value_is_missing(): void
    {
        $this->seedMasterData();

        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            'openrouter.ai/*' => Http::response($this->openRouterResponse($this->stateOneAiContent([
                'shift_pilihan' => 'pagi',
                // Bug nyata: dijawab true tapi tanggal_kunjungan kosong.
                'tanggal_kunjungan' => null,
                'tanggal_kunjungan_dijawab' => true,
            ])), 200),
            '*url=auth*' => Http::response($this->gtkOk(['token' => 'test-token']), 200),
            '*url=caripasien*' => Http::response($this->gtkFail('Data tidak ditemukan', 404), 200),
        ]);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Anak saya Budi, lahir 2021-01-01, ibu Sari, keluhan demam, laki-laki',
            ],
        ])->assertOk();

        $session = ChatSession::query()->where('chat_id', $this->chatId)->firstOrFail();
        $this->assertSame(ChatState::PengumpulanData, $session->state);
        $this->assertNull($session->no_rm);
        $this->assertSame(0, Booking::query()->count());
        $this->assertNotTrue($session->context['tanggal_kunjungan_dijawab'] ?? null);
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'tambahpasien'));
    }

    /**
     * WAHA diketahui kadang mengirim webhook event yang sama lebih dari
     * sekali (retry). Tanpa dedup, ini memicu job AI/booking berjalan dua
     * kali untuk satu pesan user yang sama - pernah menyebabkan tanggal
     * booking salah karena context ke-update di luar urutan. Pastikan
     * event kedua dengan payload.id yang sama ditolak sebelum ikut
     * dispatch job.
     */
    public function test_duplicate_webhook_event_is_ignored(): void
    {
        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            'openrouter.ai/*' => Http::response($this->openRouterResponse(json_encode([
                'reply' => 'Halo! Ada yang bisa saya bantu terkait keluhan si kecil?',
                'extracted' => [],
                'ready_for_next_state' => false,
            ])), 200),
        ]);

        $payload = [
            'event' => 'message',
            'payload' => [
                'id' => 'false_6281234567890@c.us_DUPLICATE_TEST_ID',
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Halo',
            ],
        ];

        $this->postJson('/api/whatsapp/webhook', $payload)
            ->assertOk()
            ->assertJson(['status' => 'queued']);

        $this->postJson('/api/whatsapp/webhook', $payload)
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, WhatsappMessage::where('chat_id', $this->chatId)->where('direction', 'in')->count());
        $this->assertCount(1, $fakeWa->sent);
        Http::assertSentCount(1);
    }

    /**
     * Skenario akar masalah yang dilaporkan: orang tua mengetik "Budi",
     * tapi data GTK tercatat "Budy" (typo/ejaan beda) - tanggal lahir &
     * nama ibu kandung sama persis. Sistem WAJIB menawarkan konfirmasi
     * (bukan langsung membuat pasien baru, ATAU langsung memakai kandidat
     * itu diam-diam), lalu begitu dikonfirmasi, booking harus jatuh ke
     * no_rm pasien yang SUDAH ADA - tidak boleh ada rekam medis duplikat.
     */
    public function test_probable_name_match_asks_confirmation_then_reuses_existing_no_rm(): void
    {
        $this->seedMasterData();

        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            // Jadwal kunjungan (shift_pilihan/tanggal_kunjungan) sudah
            // terkumpul sejak giliran 1 lewat stateOneAiContent() (bagian
            // formulir STATE_1 - lihat AiEngineService::stateOnePrompt()
            // "PENTING soal jadwal kunjungan"), jadi begitu giliran 2
            // mengonfirmasi kandidat pasien & no_rm didapat,
            // resolveSlotAndReply() LANGSUNG mencari slot & membuat booking
            // pada giliran yang SAMA (tidak ada lagi giliran konfirmasi slot
            // terpisah). Hanya perlu 2 giliran total.
            'openrouter.ai/*' => Http::sequence()
                ->push($this->openRouterResponse($this->stateOneAiContent(['nama' => 'Budi'])), 200)
                ->push($this->openRouterResponse($this->confirmationAiContent()), 200),
            '*url=auth*' => Http::response($this->gtkOk(['token' => 'test-token']), 200),
            '*url=caripasien*' => Http::response($this->gtkOk([
                'list' => [[
                    'no_rm' => '000050',
                    'nama' => 'Budy',
                    'jeniskelamin' => 'L',
                    'tanggallahir' => '2021-01-01',
                    'namaibu' => 'Sari',
                    'nohp' => '081234567890',
                ]],
            ]), 200),
            '*url=regpasien*' => Http::response($this->gtkOk(['no_rawat' => '2026/07/20/000001', 'no_reg' => '1'], 'Registrasi berhasil'), 200),
        ]);

        // Giliran 1: nama tidak exact match ("Budi" vs data GTK "Budy"),
        // tapi tanggal lahir & nama ibu sama - harus berhenti di pertanyaan
        // konfirmasi, BELUM pindah state & BELUM ada no_rm.
        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Anak saya Budi, lahir 2021-01-01, ibu Sari, keluhan demam, laki-laki',
            ],
        ])->assertOk();

        $session = ChatSession::query()->where('chat_id', $this->chatId)->firstOrFail();
        $this->assertSame(ChatState::PengumpulanData, $session->state);
        $this->assertNull($session->no_rm);
        $this->assertSame('000050', $session->context['pasien_ditawarkan'] ?? null);
        $this->assertCount(1, $fakeWa->sent);
        $this->assertStringContainsString('Budy', $fakeWa->sent[0]['message']);

        // Giliran 2: user konfirmasi "ya" - WAJIB memakai no_rm yang SUDAH
        // ADA (000050), bukan mendaftarkan pasien baru. resolveSlotAndReply()
        // langsung mencari slot & membuat booking pada giliran yang sama.
        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Ya, benar',
            ],
        ])->assertOk();

        $session->refresh();
        $this->assertSame(ChatState::Done, $session->state);
        $this->assertSame('000050', $session->no_rm);
        $this->assertArrayNotHasKey('pasien_ditawarkan', $session->context);
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'tambahpasien'));

        // Tidak ada duplikat Patient yang tercipta di titik ini - inilah inti
        // masalah yang dilaporkan (typo nama -> pasien lama dianggap tidak
        // ada -> rekam medis baru dibuat diam-diam).
        $booking = Booking::query()->where('no_rm', '000050')->firstOrFail();
        $this->assertSame(BookingStatus::Booked, $booking->status);
        $this->assertSame(1, Patient::query()->count());
    }

    /**
     * Sama seperti test di atas, TAPI kandidatnya HANYA ada di cache lokal
     * `patients` (mis. pasien pernah booking lewat bot ini sebelumnya) -
     * pencarian nama di sisi GTK sendiri gagal total (404). Ini satu-
     * satunya jalur yang membuktikan fallback cache lokal benar-benar
     * terpakai, bukan cuma kode mati.
     */
    public function test_probable_match_sourced_from_local_cache_when_gtk_search_fails(): void
    {
        $this->seedMasterData();

        Patient::create([
            'no_rm' => '000077',
            'nama' => 'Budy',
            'jk' => 'LAKI-LAKI',
            'tanggal_lahir' => '2021-01-01',
            'nama_ibu_kandung' => 'Sari',
            'no_hp' => '081234567890',
            'last_synced_at' => now(),
        ]);

        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push($this->openRouterResponse($this->stateOneAiContent(['nama' => 'Budi'])), 200)
                ->push($this->openRouterResponse($this->confirmationAiContent()), 200),
            '*url=auth*' => Http::response($this->gtkOk(['token' => 'test-token']), 200),
            '*url=caripasien*' => Http::response($this->gtkFail('Data tidak ditemukan', 404), 200),
            '*url=regpasien*' => Http::response($this->gtkOk(['no_rawat' => '2026/07/20/000001', 'no_reg' => '1'], 'Registrasi berhasil'), 200),
        ]);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Anak saya Budi, lahir 2021-01-01, ibu Sari, keluhan demam, laki-laki',
            ],
        ])->assertOk();

        $session = ChatSession::query()->where('chat_id', $this->chatId)->firstOrFail();
        $this->assertSame('000077', $session->context['pasien_ditawarkan'] ?? null);

        // Giliran 2: user konfirmasi "ya" - resolveSlotAndReply() langsung
        // mencari slot & membuat booking pada giliran yang sama.
        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Ya, benar',
            ],
        ])->assertOk();

        $session->refresh();
        $this->assertSame('000077', $session->no_rm);
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'tambahpasien'));
        $this->assertSame(1, Patient::query()->count());

        $booking = Booking::query()->where('no_rm', '000077')->firstOrFail();
        $this->assertSame(BookingStatus::Booked, $booking->status);
    }

    /**
     * resetIfDifferentPatient() sebelumnya SAMA SEKALI tidak punya test
     * coverage. Dua hal yang WAJIB dibuktikan sekaligus: (1) variasi
     * spasi/kapitalisasi semata TIDAK memicu reset sesi (ini micro-bug
     * yang ikut kebetulan diperbaiki oleh fix fuzzy-match - dulu trim()
     * saja tidak merapikan spasi ganda di tengah nama), (2) nama YANG
     * BENAR-BENAR berbeda (bukan typo) TETAP memicu reset total - fuzzy
     * matching di sini tidak boleh dilonggarkan sampai kehilangan
     * kemampuan mendeteksi pergantian pasien yang sungguhan.
     */
    public function test_reset_if_different_patient_tolerates_spacing_but_resets_on_different_child(): void
    {
        $this->seedMasterData();

        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push($this->openRouterResponse($this->stateOneAiContent(['nama' => 'Budi Santoso'])), 200)
                ->push($this->openRouterResponse(json_encode([
                    'reply' => 'Baik.',
                    'extracted' => ['nama' => 'BUDI   SANTOSO', 'tanggal_lahir' => '2021-01-01'],
                    'ready_for_next_state' => false,
                ])), 200)
                ->push($this->openRouterResponse(json_encode([
                    'reply' => 'Baik, siapa namanya?',
                    'extracted' => ['nama' => 'Siti Aminah', 'tanggal_lahir' => '2019-03-03'],
                    'ready_for_next_state' => false,
                ])), 200),
            '*url=auth*' => Http::response($this->gtkOk(['token' => 'test-token']), 200),
            '*url=caripasien*' => Http::response($this->gtkFail('Data tidak ditemukan', 404), 200),
            '*url=tambahpasien*' => Http::response($this->gtkOk(['no_rkm_medis' => '000099'], 'Pasien baru berhasil didaftarkan'), 200),
        ]);

        // Giliran 1: pasien baru terdaftar seperti biasa.
        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Anak saya Budi Santoso, lahir 2021-01-01, ibu Sari, keluhan demam, laki-laki',
            ],
        ])->assertOk();

        $session = ChatSession::query()->where('chat_id', $this->chatId)->firstOrFail();
        $this->assertSame('000099', $session->no_rm);
        $this->assertSame(ChatState::Konfirmasi, $session->state);

        // Giliran 2: cuma variasi KAPITALISASI + spasi ganda, tanggal lahir
        // sama persis - BUKAN pasien lain, sesi & no_rm harus tetap utuh.
        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'namanya BUDI   SANTOSO',
            ],
        ])->assertOk();

        $session->refresh();
        $this->assertSame('000099', $session->no_rm);
        $this->assertSame(ChatState::Konfirmasi, $session->state);

        // Giliran 3: nama & tanggal lahir BENAR-BENAR berbeda - ini pasien
        // lain, sesi WAJIB direset total (no_rm dilepas, balik ke STATE_1).
        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Eh maaf, mau daftarkan anak lain, Siti Aminah lahir 2019-03-03',
            ],
        ])->assertOk();

        $session->refresh();
        $this->assertNull($session->no_rm);
        $this->assertSame(ChatState::PengumpulanData, $session->state);
    }

    /**
     * jenis_layanan_dijawab WAJIB true sebelum sesi boleh pindah ke STATE_2 -
     * kalau AI keliru langsung set ready_for_next_state tanpa pernah
     * menandai field ini, server harus menahan di STATE_1 (bukan diam-diam
     * melompat lanjut tanpa pernah benar-benar menanyakan jenis layanan ke
     * pasien - dua pool kuota ini terisolasi, salah kategori berarti salah
     * kuota yang dipakai).
     */
    public function test_state_one_does_not_advance_until_jenis_layanan_is_answered(): void
    {
        $this->seedMasterData();

        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            'openrouter.ai/*' => Http::response($this->openRouterResponse($this->stateOneAiContent([
                'jenis_layanan' => null,
                'jenis_layanan_dijawab' => null,
            ])), 200),
            '*url=auth*' => Http::response($this->gtkOk(['token' => 'test-token']), 200),
            '*url=caripasien*' => Http::response($this->gtkFail('Data tidak ditemukan', 404), 200),
        ]);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Anak saya Budi, lahir 2021-01-01, ibu Sari, keluhan demam, laki-laki',
            ],
        ])->assertOk();

        $session = ChatSession::query()->where('chat_id', $this->chatId)->firstOrFail();
        $this->assertSame(ChatState::PengumpulanData, $session->state);
        $this->assertNull($session->no_rm);
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'tambahpasien'));
    }

    /**
     * Konsultasi Gizi/Tumbuh Kembang tidak lagi menanyakan jadwal kunjungan
     * (shift_pilihan/tanggal_kunjungan) sama sekali (lihat AiEngineService::
     * stateOnePrompt() "PENTING soal jadwal kunjungan") - server WAJIB tetap
     * bisa menyelesaikan booking begitu field lain lengkap, WALAU AI (persis
     * seperti instruksi prompt yang baru) tidak pernah mengisi shift_pilihan/
     * tanggal_kunjungan sama sekali. forceAutoScheduleForKonsultasi() yang
     * memaksa nilai "terdekat"/"secepatnya" di server, lalu resolveSlotAndReply()
     * mencari slot terdekat lintas SEMUA shift sendiri.
     */
    public function test_konsultasi_booking_skips_jadwal_kunjungan_question_and_auto_resolves_nearest_slot(): void
    {
        $this->seedMasterData();

        // seedMasterData() hanya menyiapkan pool default (Pemeriksaan) untuk
        // shift pagi hari ini - beri alokasi Konsultasi Tumbuh Kembang di
        // situ supaya slot ini nyata tersedia untuk kategori ini.
        DoctorSchedule::where('kode_dokter', 'D01')->where('shift', 'pagi')->update([
            'kuota_konsultasi_tumbuh_kembang' => 2,
        ]);
        QuotaShift::where('kode_dokter', 'D01')->where('shift', 'pagi')->update([
            'kuota_konsultasi_tumbuh_kembang' => 2,
        ]);

        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            'openrouter.ai/*' => Http::response($this->openRouterResponse($this->stateOneAiContent([
                'keluhan' => 'Berat badan anak susah naik',
                'jenis_layanan' => 'konsultasi_tumbuh_kembang',
                'jenis_layanan_dijawab' => true,
                'shift_pilihan' => null,
                'tanggal_kunjungan' => null,
                'tanggal_kunjungan_dijawab' => null,
            ])), 200),
            '*url=auth*' => Http::response($this->gtkOk(['token' => 'test-token']), 200),
            '*url=caripasien*' => Http::response($this->gtkFail('Data tidak ditemukan', 404), 200),
            '*url=tambahpasien*' => Http::response($this->gtkOk(['no_rkm_medis' => '000099'], 'Pasien baru berhasil didaftarkan'), 200),
            '*url=regpasien*' => Http::response($this->gtkOk(['no_rawat' => '2026/07/20/000001', 'no_reg' => '1'], 'Registrasi berhasil'), 200),
        ]);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Anak saya Budi, lahir 2021-01-01, ibu Sari, mau konsultasi tumbuh kembang, laki-laki',
            ],
        ])->assertOk();

        $session = ChatSession::query()->where('chat_id', $this->chatId)->firstOrFail();
        $this->assertSame(ChatState::Done, $session->state);

        $booking = Booking::query()->where('no_rm', '000099')->firstOrFail();
        $this->assertSame(BookingStatus::Booked, $booking->status);
        $this->assertSame(Shift::Pagi, $booking->shift);
        $this->assertSame(now()->toDateString(), $booking->tanggal_periksa->toDateString());
    }

    protected function seedMasterData(): void
    {
        $poli = Poliklinik::create([
            'kode_poliklinik' => '01',
            'nama_poliklinik' => 'Tumbuh Kembang Anak',
            'is_active' => true,
        ]);

        Doctor::create([
            'kode_dokter' => 'D01',
            'nama_dokter' => 'dr. Rina Puspita',
            'kode_poliklinik' => $poli->kode_poliklinik,
            'is_active' => true,
        ]);

        $hariMap = [
            'Monday' => 'SENIN', 'Tuesday' => 'SELASA', 'Wednesday' => 'RABU',
            'Thursday' => 'KAMIS', 'Friday' => 'JUMAT', 'Saturday' => 'SABTU', 'Sunday' => 'MINGGU',
        ];
        $todayHari = $hariMap[now()->format('l')];

        DoctorSchedule::create([
            'kode_dokter' => 'D01',
            'kode_poliklinik' => $poli->kode_poliklinik,
            'hari' => $todayHari,
            'jam_mulai' => '08:00',
            'jam_selesai' => '12:00',
            'shift' => 'pagi',
            'kuota_total' => 5,
            // source='manual' WAJIB - jadwal source='gtk' tidak lagi dipakai
            // untuk booking sama sekali (lihat filter di findNearestSlot()/
            // findSlotOnDate()/AntreanService::createBooking() dkk).
            'source' => 'manual',
        ]);

        QuotaShift::create([
            'kode_dokter' => 'D01',
            'kode_poliklinik' => $poli->kode_poliklinik,
            'tanggal' => now()->toDateString(),
            'shift' => 'pagi',
            'kuota_total' => 5,
            'kuota_terpakai' => 0,
        ]);
    }

    /**
     * Sejak jadwal kunjungan (shift_pilihan/tanggal_kunjungan) dikumpulkan
     * bersama formulir STATE_1 (bukan lagi ditanyakan terpisah di STATE_2 -
     * lihat AiEngineService::stateOnePrompt() "PENTING soal jadwal
     * kunjungan"), giliran PERTAMA yang lengkap kini LANGSUNG memicu
     * resolveSlotAndReply() (menawarkan slot), bukan cuma menyimpan data
     * pasien - defaultnya sengaja "secepatnya" supaya cocok dengan snapshot
     * kuota HARI INI yang diseed seedMasterData().
     */
    protected function stateOneAiContent(array $extractedOverrides = []): string
    {
        return json_encode([
            'reply' => 'Baik, data sudah lengkap. Silakan pilih poliklinik dan shift.',
            'extracted' => array_merge([
                'nama' => 'Budi',
                'tanggal_lahir' => '2021-01-01',
                'tempat_lahir' => 'Jombang',
                'nama_ibu_kandung' => 'Sari',
                'jenis_kelamin' => 'LAKI-LAKI',
                'no_hp' => '081234567890',
                'no_hp_dikonfirmasi' => true,
                'keluhan' => 'Demam',
                'poli_pilihan' => 'Tumbuh Kembang Anak',
                'poli_disetujui' => true,
                'jenis_layanan' => 'pemeriksaan',
                'jenis_layanan_dijawab' => true,
                'shift_pilihan' => 'pagi',
                'tanggal_kunjungan' => 'secepatnya',
                'tanggal_kunjungan_dijawab' => true,
            ], $extractedOverrides),
            'ready_for_next_state' => true,
        ]);
    }

    /**
     * Simulasi giliran user menjawab pertanyaan konfirmasi (baik konfirmasi
     * kecocokan pasien di STATE_1 maupun konfirmasi jadwal di STATE_2) -
     * "extracted" sengaja minimal (cuma "konfirmasi") karena field lain
     * sudah tersimpan di context dari giliran-giliran sebelumnya.
     */
    protected function confirmationAiContent(bool $confirmed = true, string $reply = 'Baik, terima kasih konfirmasinya.'): string
    {
        return json_encode([
            'reply' => $reply,
            'extracted' => ['konfirmasi' => $confirmed],
            'ready_for_next_state' => true,
        ]);
    }

    protected function openRouterResponse(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content]]]];
    }

    protected function gtkOk(array $response, string $message = 'Ok'): array
    {
        return ['response' => $response, 'metadata' => ['message' => $message, 'code' => 200]];
    }

    protected function gtkFail(string $message, int $code): array
    {
        return ['metadata' => ['message' => $message, 'code' => $code]];
    }
}

class FakeWhatsAppService implements WhatsAppServiceInterface
{
    /** @var array<int, array{to: string, message: string}> */
    public array $sent = [];

    public function sendText(string $to, string $message): bool
    {
        $this->sent[] = ['to' => $to, 'message' => $message];

        return true;
    }

    public function sendSeen(string $chatId): void {}

    public function startTyping(string $chatId): void {}

    public function stopTyping(string $chatId): void {}
}
