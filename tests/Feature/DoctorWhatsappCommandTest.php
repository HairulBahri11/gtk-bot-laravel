<?php

namespace Tests\Feature;

use App\Enums\ChatState;
use App\Models\ChatSession;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Poliklinik;
use App\Models\QuotaShift;
use App\Services\Whatsapp\WhatsAppServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pembeda nomor dokter vs pasien di WhatsappWebhookController (poin 3) +
 * alur perintah dokter via ProcessIncomingDoctorMessage/DoctorCommandParser
 * (poin 4) - parser terstruktur (bukan AI), jadi tidak butuh Http::fake
 * untuk OpenRouter sama sekali di jalur dokter.
 */
class DoctorWhatsappCommandTest extends TestCase
{
    use RefreshDatabase;

    protected string $doctorChatId = '6281234500000@c.us';

    protected string $kodeDokter = 'MANUAL-D01';

    protected function seedDoctor(): void
    {
        $poli = Poliklinik::create(['kode_poliklinik' => '01', 'nama_poliklinik' => 'Poli Spesialis Anak', 'is_active' => true]);

        Doctor::create([
            'kode_dokter' => $this->kodeDokter,
            'nama_dokter' => 'dr. Retno',
            'no_hp' => '081234500000',
            'kode_poliklinik' => $poli->kode_poliklinik,
            'is_active' => true,
        ]);

        $hariMap = [
            'Monday' => 'SENIN', 'Tuesday' => 'SELASA', 'Wednesday' => 'RABU',
            'Thursday' => 'KAMIS', 'Friday' => 'JUMAT', 'Saturday' => 'SABTU', 'Sunday' => 'MINGGU',
        ];

        DoctorSchedule::create([
            'kode_dokter' => $this->kodeDokter,
            'kode_poliklinik' => $poli->kode_poliklinik,
            'hari' => $hariMap[now()->format('l')],
            'jam_mulai' => '08:00',
            'jam_selesai' => '09:30',
            'shift' => 'pagi',
            'kuota_total' => 15,
            'source' => 'manual',
        ]);
    }

    public function test_doctor_number_gets_confirmation_prompt_then_executes_cancel_on_yes(): void
    {
        $this->seedDoctor();

        $fakeWa = new FakeDoctorWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            '*url=auth*' => Http::response(['response' => ['token' => 'test-token'], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200),
            '*url=batalkunjungan*' => Http::response(['response' => [], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200),
            '*url=regpasien*' => Http::response(['response' => ['no_rawat' => 'RAWAT-X', 'no_reg' => '1'], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200),
        ]);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => ['from' => $this->doctorChatId, 'fromMe' => false, 'body' => 'batalkan shift pagi hari ini'],
        ])->assertOk();

        $this->assertCount(1, $fakeWa->sent);
        $this->assertStringContainsString('Konfirmasi', $fakeWa->sent[0]['message']);

        // No AiEngineService/ChatSession involvement at all for the doctor
        // number - point 3's core guarantee.
        $this->assertSame(0, ChatSession::where('chat_id', $this->doctorChatId)->count());

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => ['from' => $this->doctorChatId, 'fromMe' => false, 'body' => 'Ya'],
        ])->assertOk();

        $this->assertCount(2, $fakeWa->sent);
        $this->assertStringContainsString('dibatalkan', $fakeWa->sent[1]['message']);

        $quota = QuotaShift::where('kode_dokter', $this->kodeDokter)->where('shift', 'pagi')->whereDate('tanggal', now()->toDateString())->first();
        $this->assertNotNull($quota);
        $this->assertSame('cancelled', $quota->status);
    }

    public function test_unrecognized_doctor_message_gets_help_text_without_touching_schedule(): void
    {
        $this->seedDoctor();

        $fakeWa = new FakeDoctorWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => ['from' => $this->doctorChatId, 'fromMe' => false, 'body' => 'halo apa kabar'],
        ])->assertOk();

        $this->assertCount(1, $fakeWa->sent);
        $this->assertStringContainsString('Perintah tidak dikenali', $fakeWa->sent[0]['message']);
        $this->assertSame(0, QuotaShift::count());
    }

    /**
     * Regresi poin 3: nomor yang TIDAK cocok dengan doctors.no_hp harus
     * tetap masuk alur pasien seperti sebelumnya, tidak berubah sama
     * sekali - dibuktikan lewat ChatSession masuk STATE_2_KONFIRMASI persis
     * seperti WhatsappWebhookTest::test_full_registration_and_booking_flow.
     */
    public function test_non_doctor_number_still_routes_to_patient_flow(): void
    {
        $poli = Poliklinik::create(['kode_poliklinik' => '01', 'nama_poliklinik' => 'Tumbuh Kembang Anak', 'is_active' => true]);
        Doctor::create(['kode_dokter' => 'D01', 'nama_dokter' => 'dr. Rina', 'kode_poliklinik' => $poli->kode_poliklinik, 'is_active' => true]);

        $hariMap = [
            'Monday' => 'SENIN', 'Tuesday' => 'SELASA', 'Wednesday' => 'RABU',
            'Thursday' => 'KAMIS', 'Friday' => 'JUMAT', 'Saturday' => 'SABTU', 'Sunday' => 'MINGGU',
        ];

        DoctorSchedule::create([
            'kode_dokter' => 'D01', 'kode_poliklinik' => $poli->kode_poliklinik,
            'hari' => $hariMap[now()->format('l')], 'jam_mulai' => '08:00', 'jam_selesai' => '12:00',
            'shift' => 'pagi', 'kuota_total' => 5,
        ]);

        $patientChatId = '6289999999999@c.us';

        $fakeWa = new FakeDoctorWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        $openRouterContent = json_encode([
            'reply' => 'Baik, data sudah lengkap.',
            'extracted' => [
                'nama' => 'Budi', 'tanggal_lahir' => '2021-01-01', 'tempat_lahir' => 'Jombang',
                'nama_ibu_kandung' => 'Sari',
                'jenis_kelamin' => 'LAKI-LAKI', 'no_hp' => '089999999999', 'no_hp_dikonfirmasi' => true,
                'keluhan' => 'Demam', 'poli_pilihan' => 'Tumbuh Kembang Anak', 'poli_disetujui' => true,
                'jenis_layanan' => 'pemeriksaan', 'jenis_layanan_dijawab' => true,
                'shift_pilihan' => 'pagi', 'tanggal_kunjungan' => 'secepatnya', 'tanggal_kunjungan_dijawab' => true,
            ],
            'ready_for_next_state' => true,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => $openRouterContent]]]], 200),
            '*url=auth*' => Http::response(['response' => ['token' => 'test-token'], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200),
            '*url=caripasien*' => Http::response(['metadata' => ['message' => 'Data tidak ditemukan', 'code' => 404]], 200),
            '*url=tambahpasien*' => Http::response(['response' => ['no_rkm_medis' => '000099'], 'metadata' => ['message' => 'Ok', 'code' => 200]], 200),
        ]);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => ['from' => $patientChatId, 'fromMe' => false, 'body' => 'Anak saya Budi, lahir 2021-01-01, ibu Sari, keluhan demam, laki-laki'],
        ])->assertOk();

        $session = ChatSession::where('chat_id', $patientChatId)->firstOrFail();
        $this->assertSame(ChatState::Konfirmasi, $session->state);
        $this->assertSame('000099', $session->no_rm);
    }
}

class FakeDoctorWhatsAppService implements WhatsAppServiceInterface
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
