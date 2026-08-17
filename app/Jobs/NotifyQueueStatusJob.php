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

    /**
     * Jeda sebelum percobaan ulang ke-2/3 (worker sudah --tries=3, lihat
     * docker-compose.yml) - kejadian nyata yang melatarbelakangi retry ini:
     * WAHA sempat gagal terkirim tanpa jejak apa pun (lihat
     * WahaWhatsAppService::post(), gagal cuma di-log bukan dilempar) kalau
     * caller tidak memeriksa nilai baliknya. Jeda beberapa detik memberi
     * waktu gangguan sesaat (mis. WAHA baru restart/sesi reconnect) pulih
     * sebelum dicoba lagi, bukan langsung menghantam gateway yang sama
     * detik itu juga.
     *
     * @var array<int, int>
     */
    public array $backoff = [5, 20];

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

        $ok = $wa->sendText(
            $booking->chatSession->chat_id,
            $antrean->buildQueueStatusMessage($booking, $this->isArrivalConfirmation),
        );

        // Beda dari kebanyakan caller sendText() lain (best-effort, gagal
        // cuma di-log) - pesan posisi antrean ini AMAN dikirim ulang (lihat
        // docblock kelas), jadi lempar exception di sini SUPAYA queue
        // worker otomatis retry, bukan diam-diam hilang tanpa jejak kalau
        // WAHA gagal sesaat.
        if (! $ok) {
            throw new \RuntimeException("Gagal mengirim WA posisi antrean untuk booking #{$this->bookingId} - lihat log WAHA sendText gagal untuk detail.");
        }
    }
}
