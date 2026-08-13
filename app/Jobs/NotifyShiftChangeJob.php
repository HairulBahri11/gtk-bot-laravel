<?php

namespace App\Jobs;

use App\Enums\Shift;
use App\Models\Booking;
use App\Models\DoctorSchedule;
use App\Models\ShiftNotificationLog;
use App\Services\Whatsapp\WhatsAppServiceInterface;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Kirim WA satu-per-satu ke pasien terdampak cancel/delay shift dokter
 * (AntreanService::cancelShiftAndReschedule()/delayShiftAndNotify()) -
 * jeda random antar pengiriman diatur di sisi dispatcher lewat ->delay(),
 * BUKAN sleep() di sini, supaya worker queue tidak tertahan sepanjang
 * durasi cascading untuk satu shift.
 *
 * Idempotent: dijaga unique(booking_id, event) di shift_notification_logs -
 * sama seperti pola reminder_logs, supaya job yang di-retry queue tidak
 * mengirim WA dobel.
 */
class NotifyShiftChangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $bookingId,
        public string $event,
        public array $payload = [],
    ) {}

    public function handle(WhatsAppServiceInterface $wa): void
    {
        $alreadySent = ShiftNotificationLog::query()
            ->where('booking_id', $this->bookingId)
            ->where('event', $this->event)
            ->exists();

        if ($alreadySent) {
            return;
        }

        $booking = Booking::with(['patient', 'doctor', 'poliklinik', 'chatSession'])->find($this->bookingId);

        if (! $booking || ! $booking->chatSession) {
            return;
        }

        $wa->sendText($booking->chatSession->chat_id, $this->buildMessage($booking));

        ShiftNotificationLog::create([
            'booking_id' => $this->bookingId,
            'event' => $this->event,
            'sent_at' => now(),
        ]);
    }

    protected function buildMessage(Booking $booking): string
    {
        $nama = $booking->patient?->nama ?? 'Bunda/Ayah';
        $poli = $booking->poliklinik?->nama_poliklinik ?? '-';
        $dokter = $booking->doctor?->nama_dokter ?? '-';
        $tanggal = (string) ($this->payload['tanggal'] ?? $booking->tanggal_periksa->toDateString());
        $tanggalLabel = Carbon::parse($tanggal)->translatedFormat('d F Y');

        return match ($this->event) {
            'rescheduled' => $this->rescheduledMessage($booking, $nama, $poli, $dokter, $tanggal, $tanggalLabel),
            'delayed' => $this->delayedMessage($booking, $nama, $poli, $dokter, $tanggal, $tanggalLabel),
            default => $this->cancelledNoAlternativeMessage($nama, $poli, $dokter, $tanggalLabel),
        };
    }

    protected function rescheduledMessage(Booking $booking, string $nama, string $poli, string $dokter, string $tanggal, string $tanggalLabel): string
    {
        $jam = $this->jamRange($booking->kode_dokter, $tanggal, $booking->shift);
        $jamLabel = $jam ? " pukul {$jam}" : '';

        return "Mohon maaf, jadwal kunjungan {$nama} ke {$poli} ({$dokter}) pada {$tanggalLabel} "
            ."dipindahkan ke shift {$booking->shift->label()}{$jamLabel} karena ada perubahan jadwal dokter. "
            .'Kami akan mengirim pengingat seperti biasa mendekati waktu kunjungan.';
    }

    protected function delayedMessage(Booking $booking, string $nama, string $poli, string $dokter, string $tanggal, string $tanggalLabel): string
    {
        $delayMinutes = (int) ($this->payload['delay_minutes'] ?? 0);
        $jam = $this->jamRange($booking->kode_dokter, $tanggal, $booking->shift);
        $efektif = $jam ? " Perkiraan jam praktik mundur {$delayMinutes} menit dari jadwal semula ({$jam})." : '';

        return "Info: jadwal kunjungan {$nama} ke {$poli} ({$dokter}) pada {$tanggalLabel} shift {$booking->shift->label()} "
            ."mengalami keterlambatan sekitar {$delayMinutes} menit.{$efektif} Mohon maaf atas ketidaknyamanannya.";
    }

    protected function cancelledNoAlternativeMessage(string $nama, string $poli, string $dokter, string $tanggalLabel): string
    {
        $alternatif = collect($this->payload['alternatif'] ?? [])
            ->map(fn (array $a) => $a['tanggal'].' shift '.Shift::from($a['shift'])->label())
            ->implode(', ');

        $saran = $alternatif !== ''
            ? " Jadwal alternatif yang masih tersedia: {$alternatif}. Balas pesan ini kalau ingin pindah ke salah satunya."
            : ' Mohon hubungi kami untuk menjadwalkan ulang kunjungan Anda.';

        return "Mohon maaf, jadwal kunjungan {$nama} ke {$poli} ({$dokter}) pada {$tanggalLabel} dibatalkan oleh dokter "
            .'dan tidak ada shift lain yang tersedia di hari yang sama.'.$saran;
    }

    protected function jamRange(string $kodeDokter, string $tanggal, Shift $shift): ?string
    {
        $hariByIso = [
            1 => 'SENIN', 2 => 'SELASA', 3 => 'RABU', 4 => 'KAMIS',
            5 => 'JUMAT', 6 => 'SABTU', 7 => 'MINGGU',
        ];
        $hari = $hariByIso[Carbon::parse($tanggal)->dayOfWeekIso] ?? null;

        $schedule = DoctorSchedule::query()
            ->where('kode_dokter', $kodeDokter)
            ->where('shift', $shift->value)
            ->where('hari', $hari)
            ->where('source', 'manual')
            ->first();

        if (! $schedule) {
            return null;
        }

        return substr($schedule->jam_mulai, 0, 5).'-'.substr($schedule->jam_selesai, 0, 5);
    }
}
