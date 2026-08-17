<?php

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Antrean\AntreanService;
use App\Services\Whatsapp\WhatsAppServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Kirim WA posisi antrean ke SATU pasien - dipakai di dua titik pemicu
 * (lihat AntreanService::confirmArrival()/completeVisit()): begitu admin
 * klik "Datang" (isArrivalConfirmation=true, sekali per booking), dan
 * begitu booking LAIN di dokter+tanggal+shift yang sama ditandai "Selesai"
 * (isArrivalConfirmation=false, dikirim ke semua pasien yang masih
 * menunggu, di-stagger oleh pemanggil - lihat notifyRemainingQueue()).
 *
 * TIDAK ada log idempotency terpisah (beda dari NotifyShiftChangeJob/
 * shift_notification_logs) - pesan posisi antrean yang terkirim ulang
 * (mis. kalau job di-retry queue) tetap konsisten dengan kenyataan
 * (menyatakan ulang fakta yang sama), tidak berbahaya seperti notifikasi
 * perubahan shift yang menegaskan SATU transisi state tertentu.
 */
class NotifyQueueStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $bookingId,
        public bool $isArrivalConfirmation,
    ) {}

    public function handle(WhatsAppServiceInterface $wa, AntreanService $antrean): void
    {
        $booking = Booking::with(['patient', 'doctor', 'chatSession'])->find($this->bookingId);

        // Booking bisa saja sudah berubah status lagi (mis. keburu Selesai/
        // dibatalkan) di antara dispatch job ini & saat benar-benar
        // diproses worker - pesan posisi antrean cuma relevan selama
        // pasien masih benar-benar berstatus Arrived (masih menunggu).
        if (! $booking || ! $booking->chatSession || $booking->status !== BookingStatus::Arrived) {
            return;
        }

        $wa->sendText(
            $booking->chatSession->chat_id,
            $antrean->buildQueueStatusMessage($booking, $this->isArrivalConfirmation),
        );
    }
}
