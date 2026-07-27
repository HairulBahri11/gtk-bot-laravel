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
                'keluhan' => 'Demam',
                'poli_pilihan' => 'Tumbuh Kembang Anak',
                'poli_disetujui' => true,
            ],
            'ready_for_next_state' => true,
        ]);
    }

    protected function stateTwoAiContent(): string
    {
        return json_encode([
            'reply' => 'Baik, booking akan diproses.',
            'extracted' => [
                'poli_pilihan' => 'Tumbuh Kembang Anak',
                'shift_pilihan' => 'pagi',
                'konfirmasi' => true,
            ],
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
