<?php

namespace App\Support;

/**
 * Skor kemiripan nama (0-100) toleran typo, dipakai PatientMatcher &
 * ProcessIncomingWhatsappMessage::resetIfDifferentPatient() untuk
 * membedakan "typo/variasi ejaan pasien yang sama" dari "memang nama
 * lain". Murni PHP native (similar_text/levenshtein) - tidak butuh
 * package pihak ketiga, dan tidak menyentuh apapun dari Laravel selain
 * kelas statis Str, supaya kelas ini bisa diuji tanpa boot framework.
 */
class NameSimilarity
{
    /**
     * Token tunggal berupa inisial (mis. "M" dari "M. Wildan") dianggap
     * cocok dengan token lain yang diawali huruf yang sama, TAPI skornya
     * sengaja dibatasi di bawah ambang batas "confident" manapun yang
     * masuk akal - inisial huruf saja bukan bukti identitas yang kuat
     * (banyak nama berbeda berawalan huruf sama), jadi tidak boleh
     * sendirian membawa skor ke status "pasti sama pasien" tanpa
     * verifikasi tambahan (lihat PatientMatcher soal tier Confident vs
     * Probable).
     */
    protected const ABBREVIATION_TOKEN_SCORE = 75.0;

    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    protected static function stripPunctuation(string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $value) ?? $value);
    }

    /**
     * @return float 0-100, 0 kalau salah satu input kosong/null.
     */
    public static function score(?string $a, ?string $b): float
    {
        $a = $a !== null ? trim($a) : '';
        $b = $b !== null ? trim($b) : '';

        if ($a === '' || $b === '') {
            return 0.0;
        }

        $normA = static::normalize($a);
        $normB = static::normalize($b);

        $wholeScore = static::wholeStringScore(static::stripPunctuation($normA), static::stripPunctuation($normB));
        $tokenScore = static::tokenScore($normA, $normB);

        return max($wholeScore, $tokenScore ?? 0.0);
    }

    /**
     * Ambil skor TERTINGGI antara similar_text (berbasis longest-common-
     * substring) dan levenshtein (berbasis edit-distance) - keduanya
     * punya titik lemah berbeda, dan mengambil nilai maksimum sengaja
     * bias ke arah recall (lebih gampang "menemukan" kecocokan) daripada
     * presisi: false negative di sini (kandidat sebenarnya sama tapi
     * tidak dianggap mirip) mereproduksi persis bug yang sedang
     * diperbaiki (pasien lama dianggap tidak ada, dibuatkan rekam medis
     * baru diam-diam), sedangkan false positive hanya berakibat satu
     * pertanyaan konfirmasi tambahan - jauh lebih murah. Gate
     * tanggal_lahir exact-match di PatientMatcher jadi pengaman presisi
     * yang independen, jadi bias recall di sini aman.
     */
    protected static function wholeStringScore(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 100.0;
        }

        similar_text($a, $b, $similarTextPercent);

        $maxLen = max(mb_strlen($a, 'UTF-8'), mb_strlen($b, 'UTF-8'));
        $levenshteinPercent = $maxLen > 0 ? (1 - levenshtein($a, $b) / $maxLen) * 100 : 100.0;

        return max($similarTextPercent, $levenshteinPercent);
    }

    /**
     * Bandingkan token per kata (hanya kalau jumlah kata di kedua sisi
     * SAMA - kalau beda jumlah kata, perbandingan per-posisi tidak lagi
     * bermakna, jadi diserahkan sepenuhnya ke wholeStringScore()) supaya
     * pola "M. Wildan" vs "Muhammad Wildan" (inisial menggantikan nama
     * depan) mendapat skor yang wajar - dibanding-bandingkan sebagai satu
     * string utuh, panjang keduanya jauh berbeda sehingga levenshtein/
     * similar_text akan menghukumnya terlalu berat.
     */
    protected static function tokenScore(string $normA, string $normB): ?float
    {
        $tokensA = array_values(array_filter(explode(' ', $normA), fn (string $t) => $t !== ''));
        $tokensB = array_values(array_filter(explode(' ', $normB), fn (string $t) => $t !== ''));

        if (count($tokensA) === 0 || count($tokensA) !== count($tokensB)) {
            return null;
        }

        $total = 0.0;

        foreach ($tokensA as $i => $tokenA) {
            $total += static::pairTokenScore($tokenA, $tokensB[$i]);
        }

        return $total / count($tokensA);
    }

    protected static function pairTokenScore(string $tokenA, string $tokenB): float
    {
        if ($tokenA === $tokenB) {
            return 100.0;
        }

        if (static::isAbbreviationMatch($tokenA, $tokenB) || static::isAbbreviationMatch($tokenB, $tokenA)) {
            return static::ABBREVIATION_TOKEN_SCORE;
        }

        return static::wholeStringScore(static::stripPunctuation($tokenA), static::stripPunctuation($tokenB));
    }

    protected static function isAbbreviationMatch(string $short, string $long): bool
    {
        $shortClean = static::stripPunctuation($short);
        $longClean = static::stripPunctuation($long);

        return mb_strlen($shortClean, 'UTF-8') === 1
            && mb_strlen($longClean, 'UTF-8') > 1
            && mb_substr($longClean, 0, 1, 'UTF-8') === $shortClean;
    }
}
