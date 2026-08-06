<?php

namespace App\Services\Antrean;

use App\Enums\BookingStatus;
use App\Enums\Shift;
use App\Models\Booking;
use App\Models\ChatSession;
use App\Models\DoctorSchedule;
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
    ) {
    }

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
}
