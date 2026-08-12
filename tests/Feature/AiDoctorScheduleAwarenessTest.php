<?php

namespace Tests\Feature;

use App\Enums\ChatState;
use App\Models\ChatSession;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Poliklinik;
use App\Models\QuotaShift;
use App\Services\Ai\AiEngineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sebelum ini, AiEngineService tidak pernah diberi data jadwal dokter nyata,
 * jadi instruksi anti-halusinasi di prompt (ATURAN WAJIB) memaksa AI
 * mengarahkan SEMUA pertanyaan jadwal ("dr. Retno hari ini jam berapa?") ke
 * admin walau datanya sudah ada di dashboard. AiEngineService::
 * doctorScheduleSummary() sekarang menyuntikkan jadwal hari ini/besok
 * (doctor_schedules + status quota_shifts) sebagai ground truth ke system
 * prompt supaya AI bisa menjawab langsung.
 */
class AiDoctorScheduleAwarenessTest extends TestCase
{
    use RefreshDatabase;

    protected function hariFor(Carbon $date): string
    {
        $map = [
            'Monday' => 'SENIN', 'Tuesday' => 'SELASA', 'Wednesday' => 'RABU',
            'Thursday' => 'KAMIS', 'Friday' => 'JUMAT', 'Saturday' => 'SABTU', 'Sunday' => 'MINGGU',
        ];

        return $map[$date->format('l')];
    }

    public function test_system_prompt_includes_todays_doctor_schedule(): void
    {
        $poli = Poliklinik::create(['kode_poliklinik' => '01', 'nama_poliklinik' => 'Poli Spesialis Anak', 'is_active' => true]);
        Doctor::create(['kode_dokter' => 'MANUAL-RETNO', 'nama_dokter' => 'dr. RA Retno Wulandari, SpA', 'kode_poliklinik' => $poli->kode_poliklinik, 'is_active' => true]);

        DoctorSchedule::create([
            'kode_dokter' => 'MANUAL-RETNO',
            'kode_poliklinik' => $poli->kode_poliklinik,
            'hari' => $this->hariFor(now()),
            'jam_mulai' => '08:00',
            'jam_selesai' => '09:30',
            'shift' => 'pagi',
            'kuota_total' => 15,
            'source' => 'manual',
        ]);

        $aiContent = json_encode([
            'reply' => 'Jadwal dr. Retno hari ini pukul 08:00-09:30.',
            'extracted' => [],
            'ready_for_next_state' => false,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => $aiContent]]]], 200),
        ]);

        $session = ChatSession::create([
            'chat_id' => '6281234567890@c.us',
            'state' => ChatState::PengumpulanData->value,
            'context' => [],
        ]);

        app(AiEngineService::class)->interpret($session, 'dr. Retno hari ini jadwal jam berapa?');

        Http::assertSent(function ($request) {
            $systemMessage = collect($request->data()['messages'])->firstWhere('role', 'system');

            return str_contains($systemMessage['content'], 'dr. RA Retno Wulandari, SpA (Poli Spesialis Anak): Pagi 08:00-09:30')
                && str_contains($systemMessage['content'], 'DATA JADWAL DOKTER');
        });
    }

    public function test_system_prompt_reflects_cancelled_status_for_today(): void
    {
        $poli = Poliklinik::create(['kode_poliklinik' => '01', 'nama_poliklinik' => 'Poli Spesialis Anak', 'is_active' => true]);
        Doctor::create(['kode_dokter' => 'MANUAL-RETNO', 'nama_dokter' => 'dr. RA Retno Wulandari, SpA', 'kode_poliklinik' => $poli->kode_poliklinik, 'is_active' => true]);

        DoctorSchedule::create([
            'kode_dokter' => 'MANUAL-RETNO',
            'kode_poliklinik' => $poli->kode_poliklinik,
            'hari' => $this->hariFor(now()),
            'jam_mulai' => '08:00',
            'jam_selesai' => '09:30',
            'shift' => 'pagi',
            'kuota_total' => 15,
            'source' => 'manual',
        ]);

        QuotaShift::create([
            'kode_dokter' => 'MANUAL-RETNO',
            'kode_poliklinik' => $poli->kode_poliklinik,
            'tanggal' => now()->toDateString(),
            'shift' => 'pagi',
            'kuota_total' => 15,
            'kuota_terpakai' => 0,
            'status' => 'cancelled',
            'reason' => 'Dokter cuti',
        ]);

        $aiContent = json_encode([
            'reply' => 'Mohon maaf, jadwal dr. Retno hari ini dibatalkan.',
            'extracted' => [],
            'ready_for_next_state' => false,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => $aiContent]]]], 200),
        ]);

        $session = ChatSession::create([
            'chat_id' => '6281234567890@c.us',
            'state' => ChatState::PengumpulanData->value,
            'context' => [],
        ]);

        app(AiEngineService::class)->interpret($session, 'dr. Retno hari ini praktik jam berapa?');

        Http::assertSent(function ($request) {
            $systemMessage = collect($request->data()['messages'])->firstWhere('role', 'system');

            return str_contains($systemMessage['content'], '[DIBATALKAN - Dokter cuti]');
        });
    }
}
