<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Poliklinik;
use App\Models\QuotaShift;
use App\Services\Whatsapp\WhatsAppServiceInterface;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AiEngineService::doctorScheduleSummary() menyuntikkan data jadwal yang
 * benar ke prompt, tapi di produksi model tetap kadang mengaku "tidak
 * memiliki data" walau datanya ADA di prompt (pola kepatuhan instruksi yang
 * tidak bisa diandalkan, terverifikasi lewat pengujian manual berulang).
 * ProcessIncomingWhatsappMessage::tryAnswerDoctorScheduleQuestion() menjawab
 * pertanyaan ini langsung dari DB, TANPA melibatkan AI - jaminan lebih kuat
 * daripada mengandalkan model mengikuti instruksi dengan benar.
 */
class DoctorScheduleFaqTest extends TestCase
{
    use RefreshDatabase;

    protected string $chatId = '6281234567890@c.us';

    protected function hariFor(Carbon $date): string
    {
        $map = [
            'Monday' => 'SENIN', 'Tuesday' => 'SELASA', 'Wednesday' => 'RABU',
            'Thursday' => 'KAMIS', 'Friday' => 'JUMAT', 'Saturday' => 'SABTU', 'Sunday' => 'MINGGU',
        ];

        return $map[$date->format('l')];
    }

    public function test_schedule_question_is_answered_without_calling_the_ai(): void
    {
        $poli = Poliklinik::create(['kode_poliklinik' => '01', 'nama_poliklinik' => 'Poli Spesialis Anak', 'is_active' => true]);
        Doctor::create(['kode_dokter' => 'MANUAL-RETNO', 'nama_dokter' => 'dr. Retno Wulandari, Sp.A', 'kode_poliklinik' => $poli->kode_poliklinik, 'is_active' => true]);
        Doctor::create(['kode_dokter' => 'GTK-RETNO', 'nama_dokter' => 'dr. Retno Wulandari, Sp.A', 'kode_poliklinik' => $poli->kode_poliklinik, 'is_active' => true]);

        DoctorSchedule::create([
            'kode_dokter' => 'MANUAL-RETNO', 'kode_poliklinik' => $poli->kode_poliklinik,
            'hari' => $this->hariFor(now()), 'jam_mulai' => '08:00', 'jam_selesai' => '09:30',
            'shift' => 'pagi', 'kuota_total' => 15, 'source' => 'manual',
        ]);

        // Baris GTK basi/kontradiktif - tidak boleh muncul di jawaban.
        DoctorSchedule::create([
            'kode_dokter' => 'GTK-RETNO', 'kode_poliklinik' => $poli->kode_poliklinik,
            'hari' => $this->hariFor(now()), 'jam_mulai' => '07:00', 'jam_selesai' => '11:00',
            'shift' => 'pagi', 'kuota_total' => 5, 'source' => 'gtk',
        ]);

        $fakeWa = new FakeScheduleFaqWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        // Sengaja TIDAK fake openrouter.ai sama sekali - kalau kode ini
        // keliru sampai memanggil AI, test akan gagal dengan connection
        // error (bukti paling kuat bahwa AI benar-benar tidak dipanggil).
        Http::preventStrayRequests();

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => ['from' => $this->chatId, 'fromMe' => false, 'body' => 'dr. Retno hari ini jadwal jam berapa?'],
        ])->assertOk();

        $this->assertCount(1, $fakeWa->sent);
        $this->assertStringContainsString('dr. Retno Wulandari, Sp.A', $fakeWa->sent[0]['message']);
        $this->assertStringContainsString('08:00-09:30', $fakeWa->sent[0]['message']);
        $this->assertStringNotContainsString('07:00-11:00', $fakeWa->sent[0]['message']);
    }

    public function test_schedule_question_reflects_cancelled_status(): void
    {
        $poli = Poliklinik::create(['kode_poliklinik' => '01', 'nama_poliklinik' => 'Poli Spesialis Anak', 'is_active' => true]);
        Doctor::create(['kode_dokter' => 'MANUAL-RETNO', 'nama_dokter' => 'dr. Retno Wulandari, Sp.A', 'kode_poliklinik' => $poli->kode_poliklinik, 'is_active' => true]);

        DoctorSchedule::create([
            'kode_dokter' => 'MANUAL-RETNO', 'kode_poliklinik' => $poli->kode_poliklinik,
            'hari' => $this->hariFor(now()), 'jam_mulai' => '08:00', 'jam_selesai' => '09:30',
            'shift' => 'pagi', 'kuota_total' => 15, 'source' => 'manual',
        ]);

        QuotaShift::create([
            'kode_dokter' => 'MANUAL-RETNO', 'kode_poliklinik' => $poli->kode_poliklinik,
            'tanggal' => now()->toDateString(), 'shift' => 'pagi',
            'kuota_total' => 15, 'kuota_terpakai' => 0, 'status' => 'cancelled', 'reason' => 'Dokter cuti',
        ]);

        $fakeWa = new FakeScheduleFaqWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);
        Http::preventStrayRequests();

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => ['from' => $this->chatId, 'fromMe' => false, 'body' => 'jadwal dr Retno hari ini jam berapa ya?'],
        ])->assertOk();

        $this->assertCount(1, $fakeWa->sent);
        $this->assertStringContainsString('DIBATALKAN', $fakeWa->sent[0]['message']);
        $this->assertStringContainsString('Dokter cuti', $fakeWa->sent[0]['message']);
    }

    /**
     * Kejadian nyata: "jadwal dokter untuk besok" (tanpa sebut nama dokter)
     * harus menampilkan SEMUA dokter yang praktik besok, bukan diam-diam
     * jatuh ke AI (yang sebelumnya kadang menjawab benar kadang tidak).
     */
    public function test_schedule_question_without_doctor_name_lists_all_doctors_for_the_day(): void
    {
        $poli = Poliklinik::create(['kode_poliklinik' => '01', 'nama_poliklinik' => 'Poli Spesialis Anak', 'is_active' => true]);
        Doctor::create(['kode_dokter' => 'MANUAL-RETNO', 'nama_dokter' => 'dr. Retno Wulandari, Sp.A', 'kode_poliklinik' => $poli->kode_poliklinik, 'is_active' => true]);
        Doctor::create(['kode_dokter' => 'MANUAL-KADEK', 'nama_dokter' => 'dr. Kadek Ayu Atrie Swarita, Sp.A', 'kode_poliklinik' => $poli->kode_poliklinik, 'is_active' => true]);

        $besok = $this->hariFor(now()->addDay());

        DoctorSchedule::create([
            'kode_dokter' => 'MANUAL-RETNO', 'kode_poliklinik' => $poli->kode_poliklinik,
            'hari' => $besok, 'jam_mulai' => '08:00', 'jam_selesai' => '09:30',
            'shift' => 'pagi', 'kuota_total' => 15, 'source' => 'manual',
        ]);
        DoctorSchedule::create([
            'kode_dokter' => 'MANUAL-KADEK', 'kode_poliklinik' => $poli->kode_poliklinik,
            'hari' => $besok, 'jam_mulai' => '09:30', 'jam_selesai' => '11:00',
            'shift' => 'pagi', 'kuota_total' => 15, 'source' => 'manual',
        ]);

        $fakeWa = new FakeScheduleFaqWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);
        Http::preventStrayRequests();

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => ['from' => $this->chatId, 'fromMe' => false, 'body' => 'jadwal dokter untuk besok'],
        ])->assertOk();

        $message = $fakeWa->sent[0]['message'];
        $this->assertStringContainsString('dr. Retno Wulandari, Sp.A', $message);
        $this->assertStringContainsString('08:00-09:30', $message);
        $this->assertStringContainsString('dr. Kadek Ayu Atrie Swarita, Sp.A', $message);
        $this->assertStringContainsString('09:30-11:00', $message);
    }

    /**
     * Kejadian nyata: "jadwal dokter retno hari jumat" menjawab jadwal
     * HARI INI+BESOK (Rabu/Kamis, termasuk shift Sore & Malam yang cuma ada
     * di Kamis) alih-alih jadwal Jumat yang sebenarnya ditanya (cuma shift
     * Pagi). Bug-nya: tanggal target tidak pernah diekstrak dari teks sama
     * sekali - selalu hardcode hari ini+besok. Reproduksi persis skenario
     * dashboard: Retno praktik Rabu/Kamis(3 shift)/Jumat(1 shift)/Sabtu.
     */
    public function test_named_doctor_with_specific_weekday_returns_only_that_days_schedule(): void
    {
        $poli = Poliklinik::create(['kode_poliklinik' => '01', 'nama_poliklinik' => 'Poli Spesialis Anak', 'is_active' => true]);
        Doctor::create(['kode_dokter' => 'MANUAL-RETNO', 'nama_dokter' => 'dr. Retno Wulandari, Sp.A', 'kode_poliklinik' => $poli->kode_poliklinik, 'is_active' => true]);

        // Jadwal Kamis (3 shift, sengaja beda dari Jumat) - kalau bug masih
        // ada, ini yang bocor muncul di jawaban meski yang ditanya Jumat.
        foreach ([['pagi', '08:00', '09:30'], ['sore', '15:30', '17:00'], ['malam', '18:30', '20:00']] as [$shift, $mulai, $selesai]) {
            DoctorSchedule::create([
                'kode_dokter' => 'MANUAL-RETNO', 'kode_poliklinik' => $poli->kode_poliklinik,
                'hari' => 'KAMIS', 'jam_mulai' => $mulai, 'jam_selesai' => $selesai,
                'shift' => $shift, 'kuota_total' => 15, 'source' => 'manual',
            ]);
        }

        // Jadwal Jumat (1 shift saja) - ini yang HARUS jadi satu-satunya isi
        // jawaban.
        DoctorSchedule::create([
            'kode_dokter' => 'MANUAL-RETNO', 'kode_poliklinik' => $poli->kode_poliklinik,
            'hari' => 'JUMAT', 'jam_mulai' => '08:00', 'jam_selesai' => '09:30',
            'shift' => 'pagi', 'kuota_total' => 15, 'source' => 'manual',
        ]);

        $fakeWa = new FakeScheduleFaqWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);
        Http::preventStrayRequests();

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => ['from' => $this->chatId, 'fromMe' => false, 'body' => 'jadwal dokter retno hari jumat'],
        ])->assertOk();

        $message = $fakeWa->sent[0]['message'];
        $this->assertStringContainsString('08:00-09:30', $message);
        // Jam yang HANYA ada di jadwal Kamis - tidak boleh bocor ke jawaban
        // untuk pertanyaan hari Jumat.
        $this->assertStringNotContainsString('15:30-17:00', $message);
        $this->assertStringNotContainsString('18:30-20:00', $message);
    }

    /**
     * Regresi: pesan biasa yang kebetulan mengandung kata "jadwal" tapi
     * TIDAK menyebut nama dokter manapun harus tetap masuk alur AI normal
     * seperti biasa (mis. user menjawab pertanyaan booking terkait jadwal
     * kunjungannya sendiri, bukan menanyakan jam praktik dokter).
     */
    public function test_message_without_a_known_doctor_name_still_goes_through_ai(): void
    {
        $fakeWa = new FakeScheduleFaqWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fakeWa);

        Http::fake([
            'openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
                'reply' => 'Baik, boleh tahu jadwal yang Anda maksud?',
                'extracted' => [],
                'ready_for_next_state' => false,
            ])]]]], 200),
        ]);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message',
            'payload' => ['from' => $this->chatId, 'fromMe' => false, 'body' => 'jadwal kunjungan saya kapan ya?'],
        ])->assertOk();

        $this->assertCount(1, $fakeWa->sent);
        $this->assertSame('Baik, boleh tahu jadwal yang Anda maksud?', $fakeWa->sent[0]['message']);
        Http::assertSentCount(1);
    }
}

class FakeScheduleFaqWhatsAppService implements WhatsAppServiceInterface
{
    /** @var array<int, array{to: string, message: string}> */
    public array $sent = [];

    public function sendText(string $to, string $message): void
    {
        $this->sent[] = ['to' => $to, 'message' => $message];
    }
}
