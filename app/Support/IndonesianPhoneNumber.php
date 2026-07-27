<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * WAHA mengirim chat_id "<nomor>@c.us" untuk kontak normal, atau "<id>@lid"
 * untuk kontak yang menyembunyikan nomor asli (fitur privasi WhatsApp) - pada
 * kasus @lid, bagian sebelum "@" adalah ID internal WhatsApp, BUKAN nomor
 * telepon sama sekali. Jangan pernah derive/tampilkan nomor dari chat_id
 * tanpa melalui validasi di sini.
 */
class IndonesianPhoneNumber
{
    public static function fromChatId(?string $chatId): ?string
    {
        if ($chatId === null || ! Str::endsWith($chatId, '@c.us')) {
            return null;
        }

        return static::normalize(Str::before($chatId, '@'));
    }

    /**
     * Normalisasi ke format 08xxxxxxxxxx, atau null kalau bukan nomor
     * seluler Indonesia yang valid.
     */
    public static function normalize(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        if (Str::startsWith($number, '62')) {
            $number = '0'.substr($number, 2);
        }

        // Nomor seluler Indonesia: diawali 08, digit ke-3 bukan 0, total
        // panjang 10-13 karakter.
        if (! preg_match('/^08[1-9][0-9]{7,10}$/', $number)) {
            return null;
        }

        return $number;
    }
}
