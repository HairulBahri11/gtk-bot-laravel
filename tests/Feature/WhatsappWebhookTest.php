<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ChatState;
use App\Models\Booking;
use App\Models\ChatSession;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
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
     * Alur end-to-end STATE_1 -> STATE_2 -> STATE_3 tanpa kredensial
     * WAHA/GTK/OpenRouter asli - seluruh HTTP eksternal di-fake, hanya
     * memverifikasi state machine, pembuatan Patient/ChatSession/Booking.
     */
    public function test_full_registration_and_booking_flow(): void
    {
        $this->seedMasterData();

        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push($this->openRouterResponse($this->stateOneAiContent()), 200)
                ->push($this->openRouterResponse($this->stateTwoAiContent()), 200),
            '*url=auth*' => Http::response($this->gtkOk(['token' => 'test-token']), 200),
            '*url=caripasien*' => Http::response($this->gtkFail('Data tidak ditemukan', 404), 200),
            '*url=tambahpasien*' => Http::response($this->gtkOk(['no_rkm_medis' => '000099'], 'Pasien baru berhasil didaftarkan'), 200),
            '*url=regpasien*' => Http::response($this->gtkOk(['no_rawat' => '2026/07/20/000001', 'no_reg' => '1'], 'Registrasi berhasil'), 200),
        ]);

        // Giliran 1 -> STATE_1: data lengkap terkumpul, pasien baru didaftarkan.
        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Anak saya Budi, lahir 2021-01-01, ibu Sari, keluhan demam, laki-laki',
            ],
        ])->assertOk();

        $session = ChatSession::query()->where('chat_id', $this->chatId)->firstOrFail();
        $this->assertSame(ChatState::Konfirmasi, $session->state);
        $this->assertSame('000099', $session->no_rm);

        // Giliran 2 -> STATE_2: pilih poli + shift, konfirmasi, booking dibuat.
        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Tumbuh Kembang Anak, shift pagi, ya konfirmasi',
            ],
        ])->assertOk();

        $session->refresh();
        $this->assertSame(ChatState::Done, $session->state);

        $booking = Booking::query()->where('no_rm', '000099')->firstOrFail();
        $this->assertSame(BookingStatus::Booked, $booking->status);
        $this->assertSame('2026/07/20/000001', $booking->no_rawat);

        $this->assertCount(2, $fakeWa->sent);
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
            'openrouter.ai/*' => Http::sequence()
                ->push($this->openRouterResponse($this->stateOneAiContent()), 200)
                ->push($this->openRouterResponse($this->stateTwoAiContent(['tanggal_kunjungan' => $requestedDate])), 200),
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

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => "Tumbuh Kembang Anak, shift pagi, tanggal {$requestedDate}, ya konfirmasi",
            ],
        ])->assertOk();

        $booking = Booking::query()->where('no_rm', '000099')->firstOrFail();
        $this->assertSame(BookingStatus::Booked, $booking->status);
        $this->assertSame($requestedDate, $booking->tanggal_periksa->toDateString());
    }

    /**
     * Pertanyaan tanggal kunjungan WAJIB diajukan & dijawab dulu - kalau AI
     * keliru langsung set konfirmasi+ready_for_next_state tanpa pernah
     * menandai tanggal_kunjungan_dijawab, server harus menolak membuat
     * booking (bukan diam-diam pakai jadwal terdekat).
     */
    public function test_booking_is_not_created_until_tanggal_kunjungan_is_answered(): void
    {
        $this->seedMasterData();

        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push($this->openRouterResponse($this->stateOneAiContent()), 200)
                ->push($this->openRouterResponse(json_encode([
                    'reply' => 'Baik, booking akan diproses.',
                    'extracted' => [
                        'poli_pilihan' => 'Tumbuh Kembang Anak',
                        'shift_pilihan' => 'pagi',
                        // AI keliru: konfirmasi+ready tanpa pernah menandai
                        // tanggal_kunjungan_dijawab - server wajib menolak ini.
                        'konfirmasi' => true,
                    ],
                    'ready_for_next_state' => true,
                ])), 200),
            '*url=auth*' => Http::response($this->gtkOk(['token' => 'test-token']), 200),
            '*url=caripasien*' => Http::response($this->gtkFail('Data tidak ditemukan', 404), 200),
            '*url=tambahpasien*' => Http::response($this->gtkOk(['no_rkm_medis' => '000099'], 'Pasien baru berhasil didaftarkan'), 200),
        ]);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Anak saya Budi, lahir 2021-01-01, ibu Sari, keluhan demam, laki-laki',
            ],
        ])->assertOk();

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Tumbuh Kembang Anak, shift pagi, ya konfirmasi',
            ],
        ])->assertOk();

        $session = ChatSession::query()->where('chat_id', $this->chatId)->firstOrFail();
        $this->assertSame(ChatState::Konfirmasi, $session->state);
        $this->assertSame(0, Booking::query()->count());
    }

    /**
     * Kejadian nyata: AI menandai tanggal_kunjungan_dijawab = true (lolos
     * gate di atas) TAPI lupa mengisi tanggal_kunjungan itu sendiri di
     * giliran yang sama - akibatnya booking diam-diam jatuh ke jadwal
     * terdekat/hari ini, bukan tanggal yang sebenarnya diminta pasien.
     * Server harus menolak & minta klarifikasi, bukan menebak "secepatnya".
     */
    public function test_booking_is_not_created_when_tanggal_kunjungan_value_is_missing(): void
    {
        $this->seedMasterData();

        $fakeWa = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push($this->openRouterResponse($this->stateOneAiContent()), 200)
                ->push($this->openRouterResponse(json_encode([
                    'reply' => 'Booking akan diproses.',
                    'extracted' => [
                        'poli_pilihan' => 'Tumbuh Kembang Anak',
                        'shift_pilihan' => 'pagi',
                        // Bug nyata: dijawab true tapi tanggal_kunjungan kosong.
                        'tanggal_kunjungan_dijawab' => true,
                        'konfirmasi' => true,
                    ],
                    'ready_for_next_state' => true,
                ])), 200),
            '*url=auth*' => Http::response($this->gtkOk(['token' => 'test-token']), 200),
            '*url=caripasien*' => Http::response($this->gtkFail('Data tidak ditemukan', 404), 200),
            '*url=tambahpasien*' => Http::response($this->gtkOk(['no_rkm_medis' => '000099'], 'Pasien baru berhasil didaftarkan'), 200),
        ]);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Anak saya Budi, lahir 2021-01-01, ibu Sari, keluhan demam, laki-laki',
            ],
        ])->assertOk();

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => [
                'from' => $this->chatId,
                'fromMe' => false,
                'body' => 'Tumbuh Kembang Anak, shift pagi, tanggal 5 Agustus 2026, ya konfirmasi',
            ],
        ])->assertOk();

        $session = ChatSession::query()->where('chat_id', $this->chatId)->firstOrFail();
        $this->assertSame(ChatState::Konfirmasi, $session->state);
        $this->assertSame(0, Booking::query()->count());
        $this->assertNotTrue($session->context['tanggal_kunjungan_dijawab'] ?? null);
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

    protected function stateOneAiContent(): string
    {
        return json_encode([
            'reply' => 'Baik, data sudah lengkap. Silakan pilih poliklinik dan shift.',
            'extracted' => [
                'nama' => 'Budi',
                'tanggal_lahir' => '2021-01-01',
                'nama_ibu_kandung' => 'Sari',
                'jenis_kelamin' => 'LAKI-LAKI',
                'no_hp' => '081234567890',
                'no_hp_dikonfirmasi' => true,
                'keluhan' => 'Demam',
                'poli_pilihan' => 'Tumbuh Kembang Anak',
                'poli_disetujui' => true,
            ],
            'ready_for_next_state' => true,
        ]);
    }

    protected function stateTwoAiContent(array $extractedOverrides = []): string
    {
        return json_encode([
            'reply' => 'Baik, booking akan diproses.',
            'extracted' => array_merge([
                'poli_pilihan' => 'Tumbuh Kembang Anak',
                'shift_pilihan' => 'pagi',
                'tanggal_kunjungan' => 'secepatnya',
                'tanggal_kunjungan_dijawab' => true,
                'konfirmasi' => true,
            ], $extractedOverrides),
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

    public function sendText(string $to, string $message): void
    {
        $this->sent[] = ['to' => $to, 'message' => $message];
    }
}
