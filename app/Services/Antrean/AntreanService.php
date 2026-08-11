<?php

namespace App\Services\Antrean;

use App\Enums\BookingStatus;
use App\Enums\Shift;
use App\Jobs\NotifyShiftChangeJob;
use App\Models\Booking;
use App\Models\ChatSession;
use App\Models\DoctorSchedule;
use App\Models\QuotaShift;
use App\Services\Gtk\GtkApiException;
use App\Services\Gtk\GtkApiService;
use App\Services\Quota\QuotaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * "Antrean": pembuatan booking, waitlist, konfirmasi kedatangan, dan
 * manajemen No-Show (geser buffer 3-5 kuota) - §3 & §5 PRD.
 */
class AntreanService
{
    public function __construct(
        protected GtkApiService $gtk,
        protected QuotaService $quota,
    ) {}

    /**
     * ISO-8601 dayOfWeekIso (1 = Senin ... 7 = Minggu) <-> nama hari yang
     * dipakai kolom DoctorSchedule::hari.
     */
    protected const HARI_BY_ISO = [
        1 => 'SENIN', 2 => 'SELASA', 3 => 'RABU', 4 => 'KAMIS',
        5 => 'JUMAT', 6 => 'SABTU', 7 => 'MINGGU',
    ];

    public function createBooking(ChatSession $session, array $data): Booking
    {
        $shift = $data['shift'] instanceof Shift ? $data['shift'] : Shift::from($data['shift']);
        $tanggal = Carbon::parse($data['tanggal_periksa'])->toDateString();

        // Titik penjagaan TERAKHIR sebelum benar-benar membuat booking -
        // WAJIB verifikasi ulang ke DoctorSchedule bahwa kode_dokter yang
        // dipakai memang benar-benar terjadwal untuk poliklinik+shift+hari
        // ini, TERLEPAS dari bagaimana caller (job WhatsApp) sampai ke nilai
        // ini. kode_dokter TIDAK PERNAH boleh berasal dari AI/tebakan -
        // findSlotOnDate()/findNearestSlot() di job sudah mengambilnya dari
        // query DB asli, tapi validasi ulang di sini (bukan cuma percaya
        // caller) supaya invariant ini tegak di titik final pembuatan
        // booking BARU - bukan tersebar & gampang lolos kalau ada jalur baru
        // yang lupa memvalidasi.
        $hariIso = Carbon::parse($tanggal)->dayOfWeekIso;
        $hari = self::HARI_BY_ISO[$hariIso] ?? null;

        $jadwalValid = DoctorSchedule::query()
            ->where('kode_dokter', $data['kode_dokter'])
            ->where('kode_poliklinik', $data['kode_poliklinik'])
            ->where('shift', $shift->value)
            ->where('hari', $hari)
            ->whereHas('doctor', fn ($q) => $q->where('is_active', true))
            ->exists();

        if (! $jadwalValid) {
            throw new RuntimeException(
                "Kode dokter {$data['kode_dokter']} tidak terjadwal untuk poliklinik {$data['kode_poliklinik']} "
                ."shift {$shift->value} pada hari {$hari} ({$tanggal}) - booking dibatalkan untuk mencegah data salah."
            );
        }

        return DB::transaction(function () use ($session, $data, $shift, $tanggal) {
            $available = $this->quota->hasAvailability($data['kode_dokter'], $tanggal, $shift);

            if (! $available) {
                return Booking::create([
                    'chat_session_id' => $session->id,
                    'no_rm' => $data['no_rm'],
                    'kode_poliklinik' => $data['kode_poliklinik'],
                    'kode_dokter' => $data['kode_dokter'],
                    'tanggal_periksa' => $tanggal,
                    'shift' => $shift->value,
                    'status' => BookingStatus::Waitlist->value,
                    'waitlist_position' => $this->nextWaitlistPosition($data['kode_dokter'], $tanggal, $shift),
                ]);
            }

            $response = $this->gtk->regPasien([
                'no_rm' => $data['no_rm'],
                'kodepoli' => $data['kode_poliklinik'],
                'kodedokter' => $data['kode_dokter'],
                'tanggalperiksa' => $tanggal,
            ]);

            $booking = Booking::create([
                'chat_session_id' => $session->id,
                'no_rm' => $data['no_rm'],
                'no_rawat' => $response['no_rawat'] ?? null,
                'no_reg' => $response['no_reg'] ?? null,
                'kode_poliklinik' => $data['kode_poliklinik'],
                'kode_dokter' => $data['kode_dokter'],
                'tanggal_periksa' => $tanggal,
                'shift' => $shift->value,
                'status' => BookingStatus::Booked->value,
            ]);

            $this->quota->reserveSlot($data['kode_dokter'], $tanggal, $shift);

            return $booking;
        });
    }

    protected function nextWaitlistPosition(string $kodeDokter, string $tanggal, Shift $shift): int
    {
        $max = Booking::query()
            ->where('kode_dokter', $kodeDokter)
            ->whereDate('tanggal_periksa', $tanggal)
            ->where('shift', $shift->value)
            ->where('status', BookingStatus::Waitlist->value)
            ->max('waitlist_position');

        return ((int) $max) + 1;
    }

    public function confirmArrival(Booking $booking): Booking
    {
        $booking->update(['status' => BookingStatus::Arrived->value]);

        return $booking->fresh();
    }

    public function cancelBooking(Booking $booking, ?string $reason = null): Booking
    {
        $this->callBatalKunjungan($booking, $reason);

        $wasHoldingSlot = in_array($booking->status, [BookingStatus::Booked, BookingStatus::Confirmed], true);

        $booking->update([
            'status' => BookingStatus::Cancelled->value,
            'cancel_reason' => $reason,
        ]);

        if ($wasHoldingSlot) {
            $this->quota->releaseSlot($booking->kode_dokter, $booking->tanggal_periksa->toDateString(), $booking->shift);
            $this->promoteWaitlist($booking->kode_dokter, $booking->tanggal_periksa->toDateString(), $booking->shift, 1);
        }

        return $booking->fresh();
    }

    /**
     * Manajemen No-Show (§3.2 PRD): batalkan kunjungan, geser buffer antrean
     * 3-5 kuota, lalu promosikan waitlist sebanyak buffer tersebut.
     */
    public function markNoShow(Booking $booking, ?string $reason = 'No-Show'): Booking
    {
        $this->callBatalKunjungan($booking, $reason);

        $buffer = random_int(
            (int) config('gtk.no_show_buffer_min'),
            (int) config('gtk.no_show_buffer_max'),
        );

        $tanggal = $booking->tanggal_periksa->toDateString();

        $booking->update([
            'status' => BookingStatus::NoShow->value,
            'cancel_reason' => $reason,
            'buffer_shifted_count' => $buffer,
        ]);

        $this->quota->releaseSlot($booking->kode_dokter, $tanggal, $booking->shift);
        $this->quota->shiftBuffer($booking->kode_dokter, $tanggal, $booking->shift, $buffer);
        $this->promoteWaitlist($booking->kode_dokter, $tanggal, $booking->shift, $buffer);

        return $booking->fresh();
    }

    protected function callBatalKunjungan(Booking $booking, ?string $reason): void
    {
        try {
            $this->gtk->batalKunjungan(array_filter([
                'no_rawat' => $booking->no_rawat,
                'no_rkm_medis' => $booking->no_rawat ? null : $booking->no_rm,
                'tanggal_kunjungan' => $booking->no_rawat ? null : $booking->tanggal_periksa->toDateString(),
                'alasan' => $reason,
            ]));
        } catch (GtkApiException $e) {
            Log::warning('batalkunjungan gagal, melanjutkan pembatalan lokal', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Promosikan pasien waitlist berikutnya (urut waitlist_position) ke
     * slot yang baru terbuka, sebanyak $limit kuota.
     */
    public function promoteWaitlist(string $kodeDokter, string $tanggal, Shift $shift, int $limit): void
    {
        $candidates = Booking::query()
            ->where('kode_dokter', $kodeDokter)
            ->whereDate('tanggal_periksa', $tanggal)
            ->where('shift', $shift->value)
            ->where('status', BookingStatus::Waitlist->value)
            ->orderBy('waitlist_position')
            ->limit($limit)
            ->get();

        foreach ($candidates as $candidate) {
            try {
                $response = $this->gtk->regPasien([
                    'no_rm' => $candidate->no_rm,
                    'kodepoli' => $candidate->kode_poliklinik,
                    'kodedokter' => $candidate->kode_dokter,
                    'tanggalperiksa' => $tanggal,
                ]);

                $candidate->update([
                    'status' => BookingStatus::Booked->value,
                    'no_rawat' => $response['no_rawat'] ?? null,
                    'no_reg' => $response['no_reg'] ?? null,
                    'waitlist_position' => null,
                ]);

                $this->quota->reserveSlot($kodeDokter, $tanggal, $shift);
            } catch (GtkApiException $e) {
                Log::warning('Gagal promosikan waitlist', [
                    'booking_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Tawarkan shift/tanggal alternatif untuk dokter yang sama saat kuota
     * penuh (§3.2 PRD).
     *
     * @return array<int, array{tanggal: string, shift: string}>
     */
    public function offerAlternative(string $kodeDokter, string $tanggal): array
    {
        return $this->quota->suggestAlternatives($kodeDokter, $tanggal);
    }

    /**
     * Perintah dokter (WA) atau dashboard: batalkan shift pada tanggal
     * tertentu. Semua booking booked/confirmed di kombinasi dokter+tanggal+
     * shift ini otomatis digeser ke shift berikutnya di hari yang sama
     * (pagi->sore->malam), geser berantai lagi kalau shift berikutnya juga
     * penuh/batal. Kalau tidak ada shift terbuka tersisa hari itu, booking
     * dibiarkan apa adanya - pasien diberi tahu + ditawarkan alternatif
     * lewat notifikasi, bukan dipindah paksa ke hari lain tanpa persetujuan
     * (pasien bisa lanjut lewat alur chat normal, intent "kunjungan_baru").
     */
    public function cancelShiftAndReschedule(string $kodeDokter, string $tanggal, Shift $shift, ?string $reason = null): QuotaShift
    {
        $quotaShift = $this->quota->cancelShift($kodeDokter, $tanggal, $shift, $reason);

        $affected = Booking::query()
            ->where('kode_dokter', $kodeDokter)
            ->whereDate('tanggal_periksa', $tanggal)
            ->where('shift', $shift->value)
            ->whereIn('status', [BookingStatus::Booked->value, BookingStatus::Confirmed->value])
            ->get();

        $staggerOffset = 0;

        foreach ($affected as $booking) {
            $staggerOffset += random_int(
                (int) config('gtk.notification_stagger_min_seconds'),
                (int) config('gtk.notification_stagger_max_seconds'),
            );

            $target = $this->findNextOpenShiftSameDay($kodeDokter, $tanggal, $shift);
            $newBooking = $target ? $this->moveBookingToShift($booking, $tanggal, $target, $reason ?? "Shift {$shift->label()} dibatalkan dokter") : null;

            if ($newBooking) {
                NotifyShiftChangeJob::dispatch($newBooking->id, 'rescheduled', [
                    'old_shift' => $shift->value,
                    'new_shift' => $target->value,
                    'tanggal' => $tanggal,
                    'reason' => $reason,
                ])->delay(now()->addSeconds($staggerOffset));

                continue;
            }

            NotifyShiftChangeJob::dispatch($booking->id, 'cancelled_no_alternative', [
                'tanggal' => $tanggal,
                'shift' => $shift->value,
                'reason' => $reason,
                'alternatif' => $this->quota->suggestAlternatives($kodeDokter, $tanggal),
            ])->delay(now()->addSeconds($staggerOffset));
        }

        return $quotaShift;
    }

    /**
     * Perintah dokter (WA) atau dashboard: tandai shift pada tanggal
     * tertentu delay N menit. Booking tidak dipindah (tetap di shift yang
     * sama) - hanya notifikasi ke pasien terdampak yang berisi jam efektif
     * baru.
     */
    public function delayShiftAndNotify(string $kodeDokter, string $tanggal, Shift $shift, int $delayMinutes, ?string $reason = null): QuotaShift
    {
        $quotaShift = $this->quota->delayShift($kodeDokter, $tanggal, $shift, $delayMinutes, $reason);

        $affected = Booking::query()
            ->where('kode_dokter', $kodeDokter)
            ->whereDate('tanggal_periksa', $tanggal)
            ->where('shift', $shift->value)
            ->whereIn('status', [BookingStatus::Booked->value, BookingStatus::Confirmed->value])
            ->get();

        $staggerOffset = 0;

        foreach ($affected as $booking) {
            $staggerOffset += random_int(
                (int) config('gtk.notification_stagger_min_seconds'),
                (int) config('gtk.notification_stagger_max_seconds'),
            );

            NotifyShiftChangeJob::dispatch($booking->id, 'delayed', [
                'tanggal' => $tanggal,
                'shift' => $shift->value,
                'delay_minutes' => $delayMinutes,
                'reason' => $reason,
            ])->delay(now()->addSeconds($staggerOffset));
        }

        return $quotaShift;
    }

    /**
     * Cari shift berikutnya (pagi->sore->malam, hari yang sama) yang masih
     * punya jadwal dokter, belum dibatalkan, dan kuotanya masih tersedia -
     * dipakai cancelShiftAndReschedule() untuk geser berantai.
     */
    protected function findNextOpenShiftSameDay(string $kodeDokter, string $tanggal, Shift $current): ?Shift
    {
        $hari = self::HARI_BY_ISO[Carbon::parse($tanggal)->dayOfWeekIso] ?? null;
        $order = [Shift::Pagi, Shift::Sore, Shift::Malam];
        $startIndex = array_search($current, $order, true) + 1;

        for ($i = $startIndex; $i < count($order); $i++) {
            $candidate = $order[$i];

            $hasSchedule = DoctorSchedule::query()
                ->where('kode_dokter', $kodeDokter)
                ->where('shift', $candidate->value)
                ->where('hari', $hari)
                ->exists();

            if (! $hasSchedule) {
                continue;
            }

            $quota = $this->quota->findQuota($kodeDokter, $tanggal, $candidate);

            // Kalau belum ada baris quota_shifts (di luar jendela sync),
            // anggap masih longgar - moveBookingToShift() memicu
            // QuotaService::resolveOrCreateQuota() saat benar-benar dipakai.
            $blocked = $quota !== null && ($quota->status === 'cancelled' || $quota->kuota_tersisa <= 0);

            if ($blocked) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Pindahkan satu booking ke shift lain pada tanggal yang sama: batalkan
     * registrasi GTK lama, daftarkan ulang untuk shift baru, tandai booking
     * lama 'rescheduled' + tautkan ke booking baru. Toleran terhadap
     * kegagalan GTK per-booking (log + lewati) supaya satu pasien yang
     * gagal tidak menggagalkan seluruh cascading untuk pasien lain - sama
     * seperti pola promoteWaitlist().
     */
    protected function moveBookingToShift(Booking $booking, string $tanggal, Shift $newShift, ?string $reason): ?Booking
    {
        try {
            $this->callBatalKunjungan($booking, $reason);

            $response = $this->gtk->regPasien([
                'no_rm' => $booking->no_rm,
                'kodepoli' => $booking->kode_poliklinik,
                'kodedokter' => $booking->kode_dokter,
                'tanggalperiksa' => $tanggal,
            ]);

            $newBooking = Booking::create([
                'chat_session_id' => $booking->chat_session_id,
                'no_rm' => $booking->no_rm,
                'no_rawat' => $response['no_rawat'] ?? null,
                'no_reg' => $response['no_reg'] ?? null,
                'kode_poliklinik' => $booking->kode_poliklinik,
                'kode_dokter' => $booking->kode_dokter,
                'tanggal_periksa' => $tanggal,
                'shift' => $newShift->value,
                'status' => BookingStatus::Booked->value,
            ]);

            $this->quota->reserveSlot($booking->kode_dokter, $tanggal, $newShift);
            $this->quota->releaseSlot($booking->kode_dokter, $booking->tanggal_periksa->toDateString(), $booking->shift);

            $booking->update([
                'status' => BookingStatus::Rescheduled->value,
                'cancel_reason' => $reason,
                'rescheduled_to_booking_id' => $newBooking->id,
            ]);

            return $newBooking;
        } catch (\Throwable $e) {
            Log::warning('Gagal memindahkan booking saat cascading reschedule', [
                'booking_id' => $booking->id,
                'target_shift' => $newShift->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
