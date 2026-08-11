<?php

namespace App\Services\Doctor;

use App\Enums\Shift;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Parser sederhana/terstruktur (BUKAN AI) untuk perintah dokter via WA -
 * sengaja dipisah dari AiEngineService supaya tidak mencampur system prompt
 * pasien yang sudah kompleks. Pasien butuh fleksibilitas bahasa natural
 * (makanya pakai Gemini/OpenRouter), perintah dokter cukup 2 pola tetap:
 * "batalkan" dan "delay/telat/tunda", jadi regex/keyword matching sudah
 * cukup dan jauh lebih murah + predictable.
 */
class DoctorCommandParser
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
     * @return array{action: string, tanggal: string, shift: Shift, delay_minutes: int|null}|null
     */
    public function parse(string $text): ?array
    {
        $normalized = Str::lower(trim($text));

        $isCancel = (bool) preg_match('/\bbatal(kan)?\b/', $normalized);
        $isDelay = (bool) preg_match('/\b(telat|delay|tunda|mundur)\b/', $normalized);

        if (! $isCancel && ! $isDelay) {
            return null;
        }

        $shift = $this->extractShift($normalized);
        $tanggal = $this->extractTanggal($normalized);

        if (! $shift || ! $tanggal) {
            return null;
        }

        if ($isDelay) {
            $delayMinutes = $this->extractMinutes($normalized);

            if (! $delayMinutes) {
                return null;
            }

            return ['action' => 'delay', 'tanggal' => $tanggal, 'shift' => $shift, 'delay_minutes' => $delayMinutes];
        }

        return ['action' => 'cancel', 'tanggal' => $tanggal, 'shift' => $shift, 'delay_minutes' => null];
    }

    public function isAffirmative(string $text): bool
    {
        return (bool) preg_match('/^(ya|iya|yes|ok|oke|benar|betul|setuju)\b/i', trim($text));
    }

    public function isNegative(string $text): bool
    {
        return (bool) preg_match('/^(tidak|batal|no|ga|gak|jangan)\b/i', trim($text));
    }

    protected function extractShift(string $text): ?Shift
    {
        return match (true) {
            str_contains($text, 'pagi') => Shift::Pagi,
            str_contains($text, 'sore') => Shift::Sore,
            str_contains($text, 'malam') => Shift::Malam,
            default => null,
        };
    }

    protected function extractMinutes(string $text): ?int
    {
        if (preg_match('/(\d+)\s*menit/', $text, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    protected function extractTanggal(string $text): ?string
    {
        if (str_contains($text, 'hari ini')) {
            return Carbon::today()->toDateString();
        }

        if (str_contains($text, 'besok')) {
            return Carbon::tomorrow()->toDateString();
        }

        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $m)) {
            try {
                return Carbon::createFromDate((int) $m[1], (int) $m[2], (int) $m[3])->toDateString();
            } catch (\Exception) {
                return null;
            }
        }

        $bulanPattern = implode('|', array_keys(self::BULAN));

        if (preg_match('/\b(\d{1,2})\s+('.$bulanPattern.')(?:\s+(\d{4}))?\b/', $text, $m)) {
            $day = (int) $m[1];
            $month = self::BULAN[$m[2]];
            $year = isset($m[3]) ? (int) $m[3] : Carbon::today()->year;

            try {
                $date = Carbon::createFromDate($year, $month, $day)->startOfDay();
            } catch (\Exception) {
                return null;
            }

            // Kalau tanggal tanpa tahun eksplisit jatuh di masa lalu (mis.
            // dokter kirim perintah akhir Desember untuk awal Januari
            // berikutnya), asumsikan tahun depan - bukan tahun ini yang
            // sudah lewat.
            if (! isset($m[3]) && $date->isPast() && ! $date->isToday()) {
                $date->addYear();
            }

            return $date->toDateString();
        }

        foreach (self::HARI as $keyword => $iso) {
            if (str_contains($text, $keyword)) {
                $today = Carbon::today();
                $diff = ($iso - $today->dayOfWeekIso + 7) % 7;

                return $today->copy()->addDays($diff)->toDateString();
            }
        }

        return null;
    }
}
