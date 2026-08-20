<?php

namespace App\Services\Quota;

use App\Enums\JenisLayanan;
use App\Enums\Shift;
use App\Models\DoctorSchedule;
use App\Models\QuotaShift;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Kuota background": pembacaan kuota real-time per shift dari cache lokal
 * (quota_shifts), bukan langsung ke API GTK setiap kali ada chat masuk
 * (§3.1 langkah 4 PRD).
 *
 * TIDAK ADA LAGI sinkronisasi apa pun ke GTK di kelas ini - poliklinik,
 * dokter, dan jadwal (doctor_schedules) semuanya murni data master yang
 * dikelola dari dashboard (PoliklinikController, DoctorController,
 * JadwalDokterController), bukan hasil tarik dari API GTK. Method
 * syncFromGtk()/syncDoctors()/syncSchedules() yang dulu ada di sini sudah
 * DIHAPUS (bukan cuma dinonaktifkan) - GtkApiService::poliklinik()/
 * dokterAktif()/jadwalDokter() tidak pernah dipanggil lagi sama sekali dari
 * mana pun di aplikasi ini.
 *
 * rebuildQuotaShifts() di bawah TETAP jalan berkala (command
 * quota:rebuild-shifts) - method itu murni proyeksi lokal dari template
 * doctor_schedules (100% manual) ke snapshot harian quota_shifts, TIDAK
 * memanggil GTK sama sekali.
 */
class QuotaService
{
    public function rebuildQuotaShifts(int $daysAhead): void
    {
        $schedules = DoctorSchedule::where('source', 'manual')->get();
        $today = Carbon::today();
        $lastDate = $today->copy()->addDays($daysAhead);

        $englishToHari = [
            'Monday' => 'SENIN', 'Tuesday' => 'SELASA', 'Wednesday' => 'RABU',
            'Thursday' => 'KAMIS', 'Friday' => 'JUMAT', 'Saturday' => 'SABTU', 'Sunday' => 'MINGGU',
        ];

        $rows = [];

        for ($i = 0; $i <= $daysAhead; $i++) {
            $date = $today->copy()->addDays($i);
            $hari = $englishToHari[$date->format('l')];

            foreach ($schedules->where('hari', $hari) as $schedule) {
                // Keyed by unique constraint (kode_dokter, tanggal, shift) - dua
                // jadwal beda jam bisa jatuh ke shift bucket yang sama pada hari
                // yang sama, jadi harus di-dedupe sebelum upsert (last wins,
                // sama seperti updateOrCreate berurutan sebelumnya).
                $key = $schedule->kode_dokter.'|'.$date->toDateString().'|'.$schedule->shift->value;

                $rows[$key] = [
                    'kode_dokter' => $schedule->kode_dokter,
                    'kode_poliklinik' => $schedule->kode_poliklinik,
                    'tanggal' => $date->toDateString(),
                    'shift' => $schedule->shift->value,
                    'kuota_total' => $schedule->kuota_total,
                    // Hanya dipakai saat baris snapshot ini PERTAMA KALI dibuat -
                    // lihat $updateColumns di bawah & docblock migration
                    // 2026_08_15_000002_split_kuota_konsultasi_gizi_tumbuh_kembang_quota_shifts.
                    'kuota_konsultasi_gizi' => $schedule->kuota_konsultasi_gizi,
                    'kuota_konsultasi_tumbuh_kembang' => $schedule->kuota_konsultasi_tumbuh_kembang,
                ];
            }
        }

        if (empty($rows)) {
            return;
        }

        // Satu query agregat untuk seluruh rentang tanggal (dikelompokkan juga
        // per jenis_layanan supaya kuota_terpakai_konsultasi ikut terhitung
        // tanpa query kedua) - bukan Booking::count() per kombinasi
        // dokter/tanggal/shift, hindari N+1 ke DB remote.
        //
        // DB::table() (bukan Booking::query()) SENGAJA dipakai di sini -
        // Booking meng-cast kolom shift/jenis_layanan ke enum (Shift/
        // JenisLayanan), dan cast itu tetap berlaku walau kolomnya datang
        // dari selectRaw()/agregat, bukan cuma SELECT biasa. Baris di bawah
        // membandingkan $row->shift/$row->jenis_layanan sebagai STRING
        // mentah (concat ke $key, dibandingkan ke ->value) - lewat
        // Booking::query() nilainya akan jadi objek enum, bukan string,
        // yang berujung TypeError saat concat & perbandingan yang selalu
        // gagal secara diam-diam saat dibandingkan ke string literal.
        $terpakaiRows = DB::table('bookings')
            ->selectRaw('kode_dokter, shift, tanggal_periksa as tanggal, jenis_layanan, COUNT(*) as total')
            ->whereBetween('tanggal_periksa', [$today->toDateString(), $lastDate->toDateString()])
            ->whereIn('status', ['booked', 'confirmed', 'arrived'])
            ->groupBy('kode_dokter', 'shift', 'tanggal_periksa', 'jenis_layanan')
            ->get();

        $terpakaiMap = [];
        $terpakaiGiziMap = [];
        $terpakaiTumbuhKembangMap = [];

        foreach ($terpakaiRows as $row) {
            $key = $row->kode_dokter.'|'.Carbon::parse($row->tanggal)->toDateString().'|'.$row->shift;
            $total = (int) $row->total;

            $terpakaiMap[$key] = ($terpakaiMap[$key] ?? 0) + $total;

            if ($row->jenis_layanan === JenisLayanan::KonsultasiGizi->value) {
                $terpakaiGiziMap[$key] = $total;
            } elseif ($row->jenis_layanan === JenisLayanan::KonsultasiTumbuhKembang->value) {
                $terpakaiTumbuhKembangMap[$key] = $total;
            }
        }

        $now = now();

        foreach ($rows as &$row) {
            $key = $row['kode_dokter'].'|'.$row['tanggal'].'|'.$row['shift'];
            $row['kuota_terpakai'] = (int) ($terpakaiMap[$key] ?? 0);
            $row['kuota_terpakai_konsultasi_gizi'] = (int) ($terpakaiGiziMap[$key] ?? 0);
            $row['kuota_terpakai_konsultasi_tumbuh_kembang'] = (int) ($terpakaiTumbuhKembangMap[$key] ?? 0);
            $row['last_synced_at'] = $now;
        }
        unset($row);

        foreach (array_chunk($rows, 500) as $chunk) {
            QuotaShift::query()->upsert(
                $chunk,
                ['kode_dokter', 'tanggal', 'shift'],
                // kuota_konsultasi_gizi/kuota_konsultasi_tumbuh_kembang SENGAJA
                // tidak diikutkan (lihat komentar saat baris ini dibangun di
                // atas) - kedua kolom kuota_terpakai_konsultasi_* WAJIB ikut,
                // sama seperti kuota_terpakai: semuanya fakta terpakai yang
                // dihitung ulang, bukan target yang diatur admin.
                ['kode_poliklinik', 'kuota_total', 'kuota_terpakai', 'kuota_terpakai_konsultasi_gizi', 'kuota_terpakai_konsultasi_tumbuh_kembang', 'last_synced_at'],
            );
        }
    }

    public function bucketShift(string $jamMulai): Shift
    {
        $windows = config('gtk.shift_windows');
        $time = substr($jamMulai, 0, 5);

        foreach ($windows as $key => $window) {
            if ($time >= $window['start'] && $time < $window['end']) {
                return Shift::from($key);
            }
        }

        return Shift::Malam;
    }

    public function findQuota(string $kodeDokter, string $tanggal, Shift $shift): ?QuotaShift
    {
        return QuotaShift::query()
            ->where('kode_dokter', $kodeDokter)
            ->whereDate('tanggal', $tanggal)
            ->where('shift', $shift->value)
            ->first();
    }

    public function hasAvailability(string $kodeDokter, string $tanggal, Shift $shift, JenisLayanan $jenis): bool
    {
        $quota = $this->findQuota($kodeDokter, $tanggal, $shift);

        return $quota !== null && $quota->status !== 'cancelled' && $quota->tersisaFor($jenis) > 0;
    }

    public function isCancelled(string $kodeDokter, string $tanggal, Shift $shift): bool
    {
        return $this->findQuota($kodeDokter, $tanggal, $shift)?->status === 'cancelled';
    }

    /**
     * Cari alternatif shift/tanggal terdekat yang masih tersedia UNTUK
     * KATEGORI YANG SAMA (pemeriksaan/konsultasi punya pool terisolasi -
     * shift dengan pemeriksaan penuh tetap bukan alternatif valid untuk
     * pasien konsultasi, begitu pula sebaliknya) untuk dokter yang sama,
     * dipakai saat kuota penuh (§3.2 PRD).
     *
     * Prefetch beberapa kali lipat $limit lalu difilter di PHP via
     * tersisaFor() (bukan whereColumn ke kolom turunan) - kuota_pemeriksaan/
     * kuota_tersisa_per-kategori bukan kolom asli, jadi tidak bisa
     * dibandingkan langsung lewat query builder.
     *
     * @return array<int, array{tanggal: string, shift: string}>
     */
    public function suggestAlternatives(string $kodeDokter, string $tanggal, JenisLayanan $jenis, int $limit = 3): array
    {
        return QuotaShift::query()
            ->where('kode_dokter', $kodeDokter)
            ->whereDate('tanggal', '>=', $tanggal)
            ->orderBy('tanggal')
            ->orderByRaw("CASE shift WHEN 'pagi' THEN 1 WHEN 'sore' THEN 2 WHEN 'malam' THEN 3 ELSE 4 END")
            ->limit($limit * 5)
            ->get()
            ->filter(fn (QuotaShift $q) => $q->status !== 'cancelled' && $q->tersisaFor($jenis) > 0)
            ->take($limit)
            ->map(fn (QuotaShift $q) => [
                'tanggal' => $q->tanggal->toDateString(),
                'shift' => $q->shift->value,
            ])
            ->values()
            ->all();
    }

    /**
     * Kolom kuota_terpakai_* tambahan yang wajib ikut naik/turun bersamaan
     * kuota_terpakai (total) untuk kategori konsultasi tertentu - null untuk
     * Pemeriksaan karena pool-nya murni turunan (kuota_total dikurangi kedua
     * alokasi konsultasi, lihat QuotaShift::kuotaFor()), tidak punya kolom
     * terpakai sendiri.
     */
    protected function terpakaiKategoriColumn(JenisLayanan $jenis): ?string
    {
        return match ($jenis) {
            JenisLayanan::KonsultasiGizi => 'kuota_terpakai_konsultasi_gizi',
            JenisLayanan::KonsultasiTumbuhKembang => 'kuota_terpakai_konsultasi_tumbuh_kembang',
            JenisLayanan::Pemeriksaan => null,
        };
    }

    public function reserveSlot(string $kodeDokter, string $tanggal, Shift $shift, JenisLayanan $jenis, int $amount = 1): void
    {
        $query = DB::table('quota_shifts')
            ->where('kode_dokter', $kodeDokter)
            ->whereDate('tanggal', $tanggal)
            ->where('shift', $shift->value);

        $kolom = $this->terpakaiKategoriColumn($jenis);

        if ($kolom) {
            // kuota_terpakai (total) WAJIB ikut naik bersamaan - ia tetap
            // representasi pemakaian gabungan ketiga kategori.
            $query->incrementEach(['kuota_terpakai' => $amount, $kolom => $amount]);
        } else {
            $query->increment('kuota_terpakai', $amount);
        }
    }

    public function releaseSlot(string $kodeDokter, string $tanggal, Shift $shift, JenisLayanan $jenis, int $amount = 1): void
    {
        $quota = $this->findQuota($kodeDokter, $tanggal, $shift);

        if (! $quota) {
            return;
        }

        $quota->kuota_terpakai = max(0, $quota->kuota_terpakai - $amount);

        $kolom = $this->terpakaiKategoriColumn($jenis);

        if ($kolom) {
            $quota->{$kolom} = max(0, $quota->{$kolom} - $amount);
        }

        $quota->save();
    }

    /**
     * Geser buffer antrean (§3.2 PRD) sebanyak N kuota tambahan pada shift
     * terkait, untuk menampung promosi waitlist setelah No-Show.
     *
     * kuota_total selalu bertambah $amount (pool gabungan memang membesar).
     * Untuk No-Show kategori Konsultasi Gizi/Tumbuh Kembang, kolom alokasi
     * kategori itu WAJIB ikut bertambah $amount juga - kalau tidak,
     * kuota_pemeriksaan turunan (kuota_total dikurangi kedua alokasi
     * konsultasi) yang justru diam-diam membesar, padahal buffer ini
     * seharusnya menambah ruang untuk mempromosikan waitlist kategori
     * konsultasi yang sama, bukan pemeriksaan.
     */
    public function shiftBuffer(string $kodeDokter, string $tanggal, Shift $shift, JenisLayanan $jenis, int $amount): void
    {
        $quota = $this->findQuota($kodeDokter, $tanggal, $shift);

        if (! $quota) {
            return;
        }

        $quota->kuota_total += $amount;

        $kolomAlokasi = match ($jenis) {
            JenisLayanan::KonsultasiGizi => 'kuota_konsultasi_gizi',
            JenisLayanan::KonsultasiTumbuhKembang => 'kuota_konsultasi_tumbuh_kembang',
            JenisLayanan::Pemeriksaan => null,
        };

        if ($kolomAlokasi) {
            $quota->{$kolomAlokasi} += $amount;
        }

        $quota->save();
    }

    /**
     * Tandai shift pada tanggal tertentu batal (dipicu perintah dokter via
     * WA atau dashboard) - booking yang sudah ada TIDAK disentuh di sini,
     * itu tanggung jawab AntreanService::cancelShiftAndReschedule() yang
     * memanggil method ini.
     */
    public function cancelShift(string $kodeDokter, string $tanggal, Shift $shift, ?string $reason = null): QuotaShift
    {
        $quota = $this->resolveOrCreateQuota($kodeDokter, $tanggal, $shift);
        $quota->update(['status' => 'cancelled', 'delay_minutes' => null, 'reason' => $reason]);

        return $quota->fresh();
    }

    /**
     * Tandai shift pada tanggal tertentu delay N menit - booking tetap di
     * shift yang sama, hanya jam efektifnya mundur (lihat AntreanService::
     * delayShiftAndNotify() untuk notifikasi ke pasien terdampak).
     */
    public function delayShift(string $kodeDokter, string $tanggal, Shift $shift, int $delayMinutes, ?string $reason = null): QuotaShift
    {
        $quota = $this->resolveOrCreateQuota($kodeDokter, $tanggal, $shift);
        $quota->update(['status' => 'delayed', 'delay_minutes' => $delayMinutes, 'reason' => $reason]);

        return $quota->fresh();
    }

    /**
     * Kembalikan shift ke status normal - dipakai dashboard sebagai koreksi
     * kalau cancel/delay salah input.
     */
    public function reopenShift(string $kodeDokter, string $tanggal, Shift $shift): QuotaShift
    {
        $quota = $this->resolveOrCreateQuota($kodeDokter, $tanggal, $shift);
        $quota->update(['status' => 'open', 'delay_minutes' => null, 'reason' => null]);

        return $quota->fresh();
    }

    /**
     * quota_shifts hanya diisi untuk N hari ke depan (lihat rebuildQuotaShifts()) -
     * kalau dokter membatalkan/delay tanggal yang belum sempat di-generate
     * (mis. baru sync semalam, atau aksi untuk tanggal di luar jendela
     * quota:rebuild-shifts), bangun barisnya di sini dari template
     * doctor_schedules supaya cancelShift()/delayShift() tidak gagal begitu
     * saja.
     *
     * PUBLIC - juga dipakai JadwalDokterController::updateKuotaTanggal()
     * (tombol "Ubah Kuota" per tanggal di dashboard Jadwal Dokter) supaya
     * admin bisa mengustomisasi kuota tanggal tertentu walau tanggalnya
     * belum sempat dibangun otomatis oleh scheduler.
     */
    public function resolveOrCreateQuota(string $kodeDokter, string $tanggal, Shift $shift): QuotaShift
    {
        $quota = $this->findQuota($kodeDokter, $tanggal, $shift);

        if ($quota) {
            return $quota;
        }

        $hari = self::HARI_BY_ISO[Carbon::parse($tanggal)->dayOfWeekIso] ?? null;

        $schedule = DoctorSchedule::query()
            ->where('kode_dokter', $kodeDokter)
            ->where('shift', $shift->value)
            ->where('hari', $hari)
            ->where('source', 'manual')
            ->first();

        if (! $schedule) {
            throw new \RuntimeException("Dokter {$kodeDokter} tidak memiliki jadwal shift {$shift->value} pada tanggal {$tanggal}.");
        }

        return QuotaShift::create([
            'kode_dokter' => $kodeDokter,
            'kode_poliklinik' => $schedule->kode_poliklinik,
            'tanggal' => $tanggal,
            'shift' => $shift->value,
            'kuota_total' => $schedule->kuota_total,
            'kuota_terpakai' => 0,
            'kuota_konsultasi_gizi' => $schedule->kuota_konsultasi_gizi,
            'kuota_terpakai_konsultasi_gizi' => 0,
            'kuota_konsultasi_tumbuh_kembang' => $schedule->kuota_konsultasi_tumbuh_kembang,
            'kuota_terpakai_konsultasi_tumbuh_kembang' => 0,
        ]);
    }

    /**
     * ISO-8601 dayOfWeekIso (1 = Senin ... 7 = Minggu) <-> nama hari yang
     * dipakai kolom DoctorSchedule::hari - sama seperti konstanta yang
     * dipakai ProcessIncomingWhatsappMessage.
     */
    protected const HARI_BY_ISO = [
        1 => 'SENIN', 2 => 'SELASA', 3 => 'RABU', 4 => 'KAMIS',
        5 => 'JUMAT', 6 => 'SABTU', 7 => 'MINGGU',
    ];
}
