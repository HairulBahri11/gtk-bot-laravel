<?php

namespace Tests\Unit\Support;

use App\Support\NameSimilarity;
use PHPUnit\Framework\TestCase;

class NameSimilarityTest extends TestCase
{
    public function test_identical_strings_score_100(): void
    {
        $this->assertSame(100.0, NameSimilarity::score('Budi', 'Budi'));
    }

    public function test_case_and_whitespace_differences_score_100(): void
    {
        $this->assertSame(100.0, NameSimilarity::score(' Budi   Santoso ', 'budi santoso'));
    }

    public function test_long_name_single_letter_typo_scores_high(): void
    {
        $score = NameSimilarity::score('Muhammad Wildan', 'Muhamad Wildan');

        $this->assertGreaterThanOrEqual(90.0, $score);
    }

    public function test_short_name_single_letter_typo_does_not_score_as_confident(): void
    {
        $score = NameSimilarity::score('Budi', 'Budy');

        $this->assertLessThan(90.0, $score);
        $this->assertGreaterThan(0.0, $score);
    }

    public function test_missing_space_between_given_names_scores_high(): void
    {
        $score = NameSimilarity::score('PutriAyu', 'Putri Ayu');

        $this->assertGreaterThanOrEqual(85.0, $score);
    }

    /**
     * Inisial huruf tunggal (mis. "M." menggantikan "Muhammad") sengaja
     * TIDAK PERNAH boleh menyamai skor nama yang benar-benar cocok persis -
     * kalau ini lolos sebagai "confident", PatientMatcher bisa langsung
     * memakai pasien lain yang kebetulan namanya sama-sama berawalan huruf
     * tersebut tanpa konfirmasi apapun.
     */
    public function test_initial_abbreviation_does_not_score_as_confident(): void
    {
        $score = NameSimilarity::score('M. Wildan', 'Muhammad Wildan');

        $this->assertLessThan(90.0, $score);
        $this->assertGreaterThan(0.0, $score);
    }

    public function test_different_word_counts_still_compare_via_whole_string(): void
    {
        $score = NameSimilarity::score('Budi Santoso Putra', 'Budi Santoso');

        $this->assertGreaterThan(0.0, $score);
        $this->assertLessThan(100.0, $score);
    }

    public function test_genuinely_different_names_score_low(): void
    {
        $score = NameSimilarity::score('Budi Santoso', 'Siti Aminah');

        // Jauh di bawah nama_weak_threshold (50) di config/gtk.php - nama
        // yang benar-benar berbeda tidak boleh mendekati ambang batas
        // manapun yang bisa memicu pertanyaan konfirmasi ke user.
        $this->assertLessThan(40.0, $score);
    }

    public function test_null_or_blank_input_scores_zero(): void
    {
        $this->assertSame(0.0, NameSimilarity::score(null, 'Budi'));
        $this->assertSame(0.0, NameSimilarity::score('Budi', null));
        $this->assertSame(0.0, NameSimilarity::score('', ''));
        $this->assertSame(0.0, NameSimilarity::score('   ', 'Budi'));
    }
}
