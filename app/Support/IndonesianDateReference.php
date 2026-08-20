<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Ekstraksi referensi tanggal dari teks bebas Bahasa Indonesia (hari ini,
 * besok, nama hari, atau tanggal eksplisit "12 Agustus"/"2026-08-12") - satu
 * implementasi dipakai bersama oleh DoctorCommandParser (perintah dokter via
 * WA) dan ProcessIncomingWhatsappMessage (pertanyaan jadwal dokter dari
 * pasien) supaya logic parsing tanggal tidak dobel/berisiko berbeda perilaku.
 */
class IndonesianDateReference
{
    protected const BULAN = [
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
        'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
        'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
    ];

    protected const HARI = [
        'senin' => 1, 'selasa' => 2, 'rabu' => 3, 'kamis' => 4,
        'jumat' => 5, "jum'at" => 5, 'sabtu' => 6, 'minggu' => 7,
    ];

    /**
     * @return string|null format yyyy-mm-dd, atau null kalau tidak ada
     *                     referensi tanggal yang bisa dikenali di teks.
     */
    public static function extract(string $text): ?string
    {
        $normalized = Str::lower($text);

        if (str_contains($normalized, 'hari ini')) {
            return Carbon::today()->toDateString();
        }

        if (str_contains($normalized, 'besok')) {
            return Carbon::tomorrow()->toDateString();
        }

        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $normalized, $m)) {
            try {
                return Carbon::createFromDate((int) $m[1], (int) $m[2], (int) $m[3])->toDateString();
            } catch (\Exception) {
                return null;
            }
        }

        $bulanPattern = implode('|', array_keys(self::BULAN));

        if (preg_match('/\b(\d{1,2})\s+('.$bulanPattern.')(?:\s+(\d{4}))?\b/', $normalized, $m)) {
            $day = (int) $m[1];
            $month = self::BULAN[$m[2]];
            $year = isset($m[3]) ? (int) $m[3] : Carbon::today()->year;

            try {
                $date = Carbon::createFromDate($year, $month, $day)->startOfDay();
            } catch (\Exception) {
                return null;
            }

            // Kalau tanggal tanpa tahun eksplisit jatuh di masa lalu (mis.
            // perintah dikirim akhir Desember untuk awal Januari berikutnya),
            // asumsikan tahun depan - bukan tahun ini yang sudah lewat.
            if (! isset($m[3]) && $date->isPast() && ! $date->isToday()) {
                $date->addYear();
            }

            return $date->toDateString();
        }

        foreach (self::HARI as $keyword => $iso) {
            if (str_contains($normalized, $keyword)) {
                $today = Carbon::today();
                $diff = ($iso - $today->dayOfWeekIso + 7) % 7;

                return $today->copy()->addDays($diff)->toDateString();
            }
        }

        return null;
    }
}
