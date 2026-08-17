<?php

namespace App\Services\Reminder;

use App\Models\Booking;
use App\Models\DoctorSchedule;
use App\Support\IndonesianPhoneNumber;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sumber kunjungan_reminder (Supabase) SEKARANG booking di aplikasi ini
 * sendiri, BUKAN lagi sync dari GTK - lihat routes/console.php &
 * app/Console/Commands/SyncKunjunganReminder.php (dinonaktifkan) untuk
 * konteks lengkap kenapa. Setiap booking yang benar-benar dapat no_rawat
 * (bukan sekadar masuk waitlist) langsung mendapat baris kunjungan_reminder
 * di sini, dengan jam_kunjungan mengikuti jam_mulai jadwal dokter yang
 * dipilih (DoctorSchedule) - BUKAN jam apapun yang mungkin dilaporkan GTK.
 *
 * Selalu tulis eksplisit ke koneksi 'pgsql' (Supabase) - sama seperti
 * SyncKunjunganReminder/DispatchKunjunganReminder - berapa pun
 * DB_CONNECTION default Laravel saat ini (mis. mysql lokal saat dev/test).
 */
class KunjunganReminderService
{
    /**
     * ISO-8601 dayOfWeekIso (1 = Senin ... 7 = Minggu) <-> nama hari yang
     * dipakai kolom DoctorSchedule::hari - duplikat kecil dari konstanta
     * yang sama di AntreanService/ProcessIncomingWhatsappMessage (pola
     * yang sudah dipakai di beberapa tempat lain di codebase ini, bukan
     * hal baru).
     */
    protected const HARI_BY_ISO = [
        1 => 'SENIN', 2 => 'SELASA', 3 => 'RABU', 4 => 'KAMIS',
        5 => 'JUMAT', 6 => 'SABTU', 7 => 'MINGGU',
    ];

    /**
     * Buat/perbarui baris kunjungan_reminder untuk booking yang BENAR-BENAR
     * sudah dapat no_rawat (bukan waitlist - kunjungan waitlist belum pasti
     * kapan/apakah akan benar-benar terjadi, jadi belum pantas diingatkan).
     * Idempoten lewat updateOrInsert keyed no_rawat (primary key tabel ini) -
     * aman dipanggil ulang untuk booking yang sama.
     *
     * SENGAJA tidak menyentuh kolom reminder_*_status - default DB
     * ('pending') sudah cukup untuk baris baru, dan menimpanya lagi di sini
     * berisiko mengulang reminder yang sudah terkirim kalau method ini
     * suatu saat terpanggil dua kali untuk no_rawat yang sama.
     */
    public function upsertForBooking(Booking $booking): void
    {
        if (blank($booking->no_rawat)) {
            return;
        }

        $hari = self::HARI_BY_ISO[Carbon::parse($booking->tanggal_periksa)->dayOfWeekIso] ?? null;

        $jadwal = DoctorSchedule::query()
            ->where('kode_dokter', $booking->kode_dokter)
            ->where('kode_poliklinik', $booking->kode_poliklinik)
            ->where('shift', $booking->shift->value)
            ->where('hari', $hari)
            ->where('source', 'manual')
            ->first();

        if (! $jadwal) {
            Log::warning('Tidak bisa membuat kunjungan_reminder - jadwal dokter tidak ditemukan', [
                'booking_id' => $booking->id,
                'no_rawat' => $booking->no_rawat,
            ]);

            return;
        }

        $nohpRaw = $booking->patient?->no_hp;
        $nohp = IndonesianPhoneNumber::toInternational($nohpRaw);

        try {
            DB::connection('pgsql')->table('kunjungan_reminder')->updateOrInsert(
                ['no_rawat' => $booking->no_rawat],
                [
                    'nama_pasien' => $booking->patient?->nama ?? '-',
                    'nohp_raw' => $nohpRaw,
                    'nohp' => $nohp,
                    'kodepoli' => $booking->kode_poliklinik,
                    'poli' => $booking->poliklinik?->nama_poliklinik,
                    'dokter' => $booking->doctor?->nama_dokter,
                    'tanggal_kunjungan' => $booking->tanggal_periksa->toDateString(),
                    'jam_kunjungan' => $jadwal->jam_mulai,
                    'status_kunjungan' => 'active',
                    'last_synced_at' => now(),
                ],
            );
        } catch (\Throwable $e) {
            // Gangguan Supabase TIDAK BOLEH menggagalkan alur booking/
            // kedatangan/pembatalan pasien - sama seperti toleransi
            // kegagalan GTK di AntreanService (promoteWaitlist()/
            // moveBookingToShift()), cukup log & lanjutkan.
            Log::warning('Gagal upsert kunjungan_reminder', [
                'booking_id' => $booking->id,
                'no_rawat' => $booking->no_rawat,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tandai kunjungan_reminder batal - dipanggil begitu booking-nya sendiri
     * dibatalkan/no-show/dipindah ke shift lain, supaya pasien tidak terus
     * diingatkan untuk kunjungan yang sudah tidak berlaku. Update status
     * (BUKAN hapus baris) supaya riwayat reminder_log yang sudah terkirim
     * tetap ada (FK cascade akan ikut menghapusnya kalau baris ini dihapus).
     */
    public function cancelForBooking(Booking $booking): void
    {
        if (blank($booking->no_rawat)) {
            return;
        }

        try {
            DB::connection('pgsql')->table('kunjungan_reminder')
                ->where('no_rawat', $booking->no_rawat)
                ->update(['status_kunjungan' => 'cancelled']);
        } catch (\Throwable $e) {
            Log::warning('Gagal membatalkan kunjungan_reminder', [
                'booking_id' => $booking->id,
                'no_rawat' => $booking->no_rawat,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
