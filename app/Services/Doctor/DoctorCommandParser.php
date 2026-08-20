<?php

namespace App\Services\Doctor;

use App\Enums\Shift;
use App\Support\IndonesianDateReference;
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
        return IndonesianDateReference::extract($text);
    }
}
