<?php

namespace App\Jobs;

use App\Enums\Shift;
use App\Models\DoctorSchedule;
use App\Models\WhatsappMessage;
use App\Services\Antrean\AntreanService;
use App\Services\Doctor\DoctorCommandParser;
use App\Services\Whatsapp\WhatsAppServiceInterface;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Orkestrator perintah dokter via WA (batalkan/delay shift) - sengaja
 * TERPISAH TOTAL dari ProcessIncomingWhatsappMessage/AiEngineService/
 * ChatState: dokter tidak pernah masuk ke state machine pasien sama sekali
 * (lihat pembedaan nomor di WhatsappWebhookController). Parsing pakai
 * DoctorCommandParser (regex terstruktur, bukan AI) karena hanya perlu 2
 * pola perintah tetap.
 */
class ProcessIncomingDoctorMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(
        public string $chatId,
        public string $text,
        public string $kodeDokter,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->chatId))->expireAfter(120)->releaseAfter(5)];
    }

    public function handle(DoctorCommandParser $parser, AntreanService $antrean, WhatsAppServiceInterface $wa): void
    {
        $pending = Cache::get($this->cacheKey());

        if ($pending && $parser->isAffirmative($this->text)) {
            Cache::forget($this->cacheKey());
            $this->execute($pending, $antrean, $wa);

            return;
        }

        if ($pending && $parser->isNegative($this->text)) {
            Cache::forget($this->cacheKey());
            $this->reply($wa, 'Baik, perintah dibatalkan.');

            return;
        }

        $command = $parser->parse($this->text);

        if (! $command) {
            $message = $pending
                ? 'Mohon balas "Ya" untuk konfirmasi, atau "Tidak" untuk membatalkan perintah sebelumnya.'
                : $this->helpMessage();

            $this->reply($wa, $message);

            return;
        }

        if (! $this->doctorHasSchedule($command['shift'], $command['tanggal'])) {
            $this->reply($wa, "Anda tidak memiliki jadwal shift {$command['shift']->label()} pada tanggal {$command['tanggal']}.");

            return;
        }

        Cache::put($this->cacheKey(), $command, now()->addMinutes(10));
        $this->reply($wa, $this->confirmationPrompt($command));
    }

    /**
     * @param  array{action: string, tanggal: string, shift: Shift, delay_minutes: int|null}  $command
     */
    protected function execute(array $command, AntreanService $antrean, WhatsAppServiceInterface $wa): void
    {
        $shift = $command['shift'];

        if ($command['action'] === 'cancel') {
            $antrean->cancelShiftAndReschedule($this->kodeDokter, $command['tanggal'], $shift, 'Dibatalkan dokter via WhatsApp');

            $this->reply($wa, "Baik, shift {$shift->label()} tanggal {$command['tanggal']} sudah dibatalkan. "
                .'Pasien terdampak akan otomatis diberi tahu/dijadwalkan ulang.');

            return;
        }

        $antrean->delayShiftAndNotify($this->kodeDokter, $command['tanggal'], $shift, (int) $command['delay_minutes'], 'Delay dilaporkan dokter via WhatsApp');

        $this->reply($wa, "Baik, shift {$shift->label()} tanggal {$command['tanggal']} dicatat delay {$command['delay_minutes']} menit. "
            .'Pasien terdampak akan otomatis diberi tahu.');
    }

    /**
     * @param  array{action: string, tanggal: string, shift: Shift, delay_minutes: int|null}  $command
     */
    protected function confirmationPrompt(array $command): string
    {
        $shift = $command['shift'];

        if ($command['action'] === 'cancel') {
            return "Konfirmasi: batalkan shift {$shift->label()} tanggal {$command['tanggal']}? "
                .'Pasien yang sudah booking akan otomatis digeser ke shift berikutnya/diberi tahu. Balas "Ya" untuk konfirmasi.';
        }

        return "Konfirmasi: shift {$shift->label()} tanggal {$command['tanggal']} delay {$command['delay_minutes']} menit? "
            .'Pasien terdampak akan diberi tahu. Balas "Ya" untuk konfirmasi.';
    }

    protected function helpMessage(): string
    {
        return "Perintah tidak dikenali. Format yang didukung:\n"
            ."- Batalkan shift: \"batalkan shift pagi hari ini\" atau \"batalkan shift sore 15 Agustus\"\n"
            .'- Lapor delay: "delay shift sore 30 menit hari ini"';
    }

    protected function doctorHasSchedule(Shift $shift, string $tanggal): bool
    {
        $hariByIso = [
            1 => 'SENIN', 2 => 'SELASA', 3 => 'RABU', 4 => 'KAMIS',
            5 => 'JUMAT', 6 => 'SABTU', 7 => 'MINGGU',
        ];
        $hari = $hariByIso[Carbon::parse($tanggal)->dayOfWeekIso] ?? null;

        return DoctorSchedule::query()
            ->where('kode_dokter', $this->kodeDokter)
            ->where('shift', $shift->value)
            ->where('hari', $hari)
            ->where('source', 'manual')
            ->exists();
    }

    protected function cacheKey(): string
    {
        return "doctor_cmd:{$this->chatId}";
    }

    protected function reply(WhatsAppServiceInterface $wa, string $message): void
    {
        $wa->sendText($this->chatId, $message);

        WhatsappMessage::create([
            'chat_id' => $this->chatId,
            'direction' => 'out',
            'message' => $message,
        ]);
    }
}
