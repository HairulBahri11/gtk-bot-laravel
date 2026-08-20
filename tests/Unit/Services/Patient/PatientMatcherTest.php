<?php

namespace Tests\Unit\Services\Patient;

use App\Enums\PatientMatchVerdict;
use App\Services\Patient\PatientMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Ambang batas dilewatkan eksplisit ke constructor (bukan lewat
 * config('gtk.patient_matching')) supaya test ini murni PHPUnit\Framework\TestCase
 * tanpa perlu boot Laravel - konsisten dengan tests/Unit/ExampleTest.php &
 * cara DoctorCommandParser diuji di kelas ini.
 */
class PatientMatcherTest extends TestCase
{
    protected function matcher(): PatientMatcher
    {
        return new PatientMatcher([
            'nama_confident_threshold' => 90,
            'nama_probable_threshold' => 65,
            'nama_weak_threshold' => 50,
            'nama_ibu_moderate_threshold' => 70,
        ]);
    }

    protected function candidate(string $noRm, ?string $nama, ?string $tanggalLahir, ?string $namaIbu = null, ?string $noHp = null): array
    {
        return [
            'no_rm' => $noRm,
            'nama' => $nama,
            'tanggal_lahir' => $tanggalLahir,
            'nama_ibu_kandung' => $namaIbu,
            'no_hp' => $noHp,
        ];
    }

    public function test_exact_nama_and_exact_dob_is_confident(): void
    {
        $result = $this->matcher()->evaluate(
            ['nama' => 'Budi Santoso', 'tanggal_lahir' => '2021-01-01', 'nama_ibu_kandung' => null, 'no_hp' => null],
            [$this->candidate('001', 'Budi Santoso', '2021-01-01')],
        );

        $this->assertSame(PatientMatchVerdict::Confident, $result['verdict']);
        $this->assertSame('001', $result['candidate']['no_rm']);
    }

    public function test_long_name_single_letter_typo_with_exact_dob_is_confident(): void
    {
        $result = $this->matcher()->evaluate(
            ['nama' => 'Muhammad Wildan', 'tanggal_lahir' => '2021-01-01', 'nama_ibu_kandung' => null, 'no_hp' => null],
            [$this->candidate('002', 'Muhamad Wildan', '2021-01-01')],
        );

        $this->assertSame(PatientMatchVerdict::Confident, $result['verdict']);
    }

    public function test_moderate_typo_with_exact_dob_is_probable(): void
    {
        $result = $this->matcher()->evaluate(
            ['nama' => 'Budi', 'tanggal_lahir' => '2021-01-01', 'nama_ibu_kandung' => null, 'no_hp' => null],
            [$this->candidate('003', 'Budy', '2021-01-01')],
        );

        $this->assertSame(PatientMatchVerdict::Probable, $result['verdict']);
    }

    /**
     * tanggal_lahir adalah anchor WAJIB persis sama (lihat docblock
     * PatientMatcher) - kandidat dengan nama identik tapi tanggal lahir
     * beda WAJIB dibuang total, tidak peduli semirip apapun namanya.
     */
    public function test_exact_nama_but_wrong_dob_is_no_match(): void
    {
        $result = $this->matcher()->evaluate(
            ['nama' => 'Budi Santoso', 'tanggal_lahir' => '2021-01-01', 'nama_ibu_kandung' => null, 'no_hp' => null],
            [$this->candidate('004', 'Budi Santoso', '2020-05-05')],
        );

        $this->assertSame(PatientMatchVerdict::NoMatch, $result['verdict']);
        $this->assertNull($result['candidate']);
    }

    /**
     * no_hp TIDAK PERNAH boleh sendirian cukup untuk status Confident -
     * nomor WA bisa berubah/dipakai bergantian, jadi walau nomornya persis
     * sama, kalau nama cuma "lumayan mirip" hasilnya paling tinggi Probable
     * (masih wajib tanya user), bukan langsung dipakai diam-diam.
     */
    public function test_weak_nama_with_matching_no_hp_never_reaches_confident(): void
    {
        $result = $this->matcher()->evaluate(
            ['nama' => 'Naila', 'tanggal_lahir' => '2021-01-01', 'nama_ibu_kandung' => null, 'no_hp' => '081234567890'],
            [$this->candidate('005', 'Naura', '2021-01-01', null, '081234567890')],
        );

        $this->assertNotSame(PatientMatchVerdict::Confident, $result['verdict']);
        $this->assertSame(PatientMatchVerdict::Probable, $result['verdict']);
        $this->assertTrue($result['no_hp_exact']);
    }

    /**
     * Regresi langsung untuk bug akar masalah: kode lama mengambil
     * list[0] apa adanya tanpa menilai kandidat lain - matcher WAJIB
     * memilih kandidat dengan skor terbaik, bukan cuma yang pertama
     * muncul di array input.
     */
    public function test_best_scoring_candidate_wins_even_when_not_first_in_list(): void
    {
        $result = $this->matcher()->evaluate(
            ['nama' => 'Budi Santoso', 'tanggal_lahir' => '2021-01-01', 'nama_ibu_kandung' => null, 'no_hp' => null],
            [
                $this->candidate('006a', 'Siti Aminah', '2021-01-01'),
                $this->candidate('006b', 'Budi Santoso', '2021-01-01'),
            ],
        );

        $this->assertSame(PatientMatchVerdict::Confident, $result['verdict']);
        $this->assertSame('006b', $result['candidate']['no_rm']);
    }

    public function test_empty_candidate_list_is_no_match(): void
    {
        $result = $this->matcher()->evaluate(
            ['nama' => 'Budi Santoso', 'tanggal_lahir' => '2021-01-01', 'nama_ibu_kandung' => null, 'no_hp' => null],
            [],
        );

        $this->assertSame(PatientMatchVerdict::NoMatch, $result['verdict']);
        $this->assertNull($result['candidate']);
    }

    /**
     * nama_ibu_kandung boleh MENAIKKAN status lemah ke Probable (layak
     * ditanyakan), tapi lihat test_strong_nama_ignores_mismatched_nama_ibu()
     * untuk batasnya: tidak pernah sampai ke Confident.
     */
    public function test_weak_nama_corroborated_by_strong_nama_ibu_is_promoted_to_probable(): void
    {
        $result = $this->matcher()->evaluate(
            ['nama' => 'Naila', 'tanggal_lahir' => '2021-01-01', 'nama_ibu_kandung' => 'Sari', 'no_hp' => null],
            [$this->candidate('008', 'Naura', '2021-01-01', 'Sari')],
        );

        $this->assertSame(PatientMatchVerdict::Probable, $result['verdict']);
    }

    /**
     * Kebalikan dari test di atas: nama_ibu_kandung yang BEDA tidak boleh
     * menambah friksi pada kecocokan nama yang sudah kuat - data nama ibu
     * yang basi/salah catat di sistem lama tidak boleh menghalangi match
     * yang sebenarnya sudah jelas benar.
     */
    public function test_strong_nama_ignores_mismatched_nama_ibu(): void
    {
        $result = $this->matcher()->evaluate(
            ['nama' => 'Budi Santoso', 'tanggal_lahir' => '2021-01-01', 'nama_ibu_kandung' => 'Sari', 'no_hp' => null],
            [$this->candidate('009', 'Budi Santoso', '2021-01-01', 'Ratna')],
        );

        $this->assertSame(PatientMatchVerdict::Confident, $result['verdict']);
    }

    public function test_null_nama_ibu_kandung_does_not_error_and_falls_back_to_nama_only(): void
    {
        $result = $this->matcher()->evaluate(
            ['nama' => 'Budi', 'tanggal_lahir' => '2021-01-01', 'nama_ibu_kandung' => 'Sari', 'no_hp' => null],
            [$this->candidate('010', 'Budy', '2021-01-01', null)],
        );

        $this->assertSame(PatientMatchVerdict::Probable, $result['verdict']);
        $this->assertNull($result['nama_ibu_score']);
    }

    public function test_tie_break_prefers_first_candidate_in_input_order(): void
    {
        $result = $this->matcher()->evaluate(
            ['nama' => 'Budi', 'tanggal_lahir' => '2021-01-01', 'nama_ibu_kandung' => null, 'no_hp' => null],
            [
                $this->candidate('011a', 'Budi', '2021-01-01'),
                $this->candidate('011b', 'Budi', '2021-01-01'),
            ],
        );

        $this->assertSame('011a', $result['candidate']['no_rm']);
    }

    public function test_missing_submitted_tanggal_lahir_is_no_match(): void
    {
        $result = $this->matcher()->evaluate(
            ['nama' => 'Budi Santoso', 'tanggal_lahir' => null, 'nama_ibu_kandung' => null, 'no_hp' => null],
            [$this->candidate('012', 'Budi Santoso', '2021-01-01')],
        );

        $this->assertSame(PatientMatchVerdict::NoMatch, $result['verdict']);
    }
}
