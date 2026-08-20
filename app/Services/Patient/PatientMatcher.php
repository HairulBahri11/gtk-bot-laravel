<?php

namespace App\Services\Patient;

use App\Enums\PatientMatchVerdict;
use App\Support\IndonesianPhoneNumber;
use App\Support\NameSimilarity;

/**
 * Mesin pencocokan identitas pasien berlapis - dipakai saat pencarian nama
 * exact ke GTK gagal (typo/ejaan beda) supaya pasien lama tidak keliru
 * dianggap tidak ada lalu didaftarkan ulang sebagai rekam medis baru (lihat
 * ProcessIncomingWhatsappMessage::resolvePatient()).
 *
 * PURE logic - TIDAK ADA I/O di sini (tidak memanggil GtkApiService, tidak
 * query Eloquent apapun). Pemanggil bertanggung jawab mengumpulkan kandidat
 * (dari GTK & cache lokal) jadi array biasa sebelum diserahkan ke sini -
 * supaya kelas ini bisa diuji penuh dengan array buatan tangan tanpa perlu
 * DB/HTTP/boot Laravel.
 *
 * Prioritas sinyal sesuai permintaan produk - tanggal_lahir sebagai anchor
 * WAJIB persis sama (orang tua jarang salah ketik tanggal lahir anak
 * sendiri seperti mereka salah ketik ejaan nama) - kandidat dengan
 * tanggal_lahir berbeda LANGSUNG dibuang, tidak peduli semirip apapun
 * namanya. nama adalah sinyal UTAMA yang toleran typo (lihat
 * NameSimilarity) - HANYA nama yang bisa membawa status ke Confident
 * (auto-pakai tanpa tanya). nama_ibu_kandung & no_hp HANYA berperan sebagai
 * penguat kalau nama sudah "lumayan mirip" (menaikkan ke Probable, minta
 * konfirmasi) - PENTING: keduanya TIDAK PERNAH bisa menaikkan status ke
 * Confident sendirian atau bersama-sama. Ini sengaja dibatasi begini karena
 * anak KEMBAR berbagi tanggal lahir & nama ibu yang PERSIS SAMA - kalau
 * nama_ibu_kandung boleh mendongkrak skor ke Confident, booking anak kembar
 * A bisa diam-diam "nyasar" ke rekam medis kembar B hanya karena nama
 * mereka kebetulan agak mirip (nama bertema kembar cukup umum di
 * Indonesia). no_hp dibatasi peran serupa karena nomor WA bisa berubah/
 * dipakai bergantian antar anggota keluarga - tidak boleh jadi bukti
 * identitas yang berdiri sendiri.
 */
class PatientMatcher
{
    public function __construct(protected ?array $thresholds = null)
    {
        $this->thresholds ??= config('gtk.patient_matching');
    }

    /**
     * @param  array{nama: ?string, tanggal_lahir: ?string, nama_ibu_kandung: ?string, no_hp: ?string}  $submitted
     * @param  array<int, array{no_rm: string, nama: ?string, tanggal_lahir: ?string, nama_ibu_kandung: ?string, no_hp: ?string}>  $candidates
     *                                                                                                                                          Key lain selain 5 di atas (mis. "source"/"raw") boleh ada & diabaikan -
     *                                                                                                                                          pemanggil bebas menumpangkan data tambahan untuk dipakai sendiri
     *                                                                                                                                          setelah evaluate() mengembalikan kandidat pemenang.
     * @return array{verdict: PatientMatchVerdict, candidate: array|null, nama_score: float|null, nama_ibu_score: float|null, no_hp_exact: bool|null}
     */
    public function evaluate(array $submitted, array $candidates): array
    {
        $tanggalLahir = $submitted['tanggal_lahir'] ?? null;

        if ($tanggalLahir === null || $tanggalLahir === '') {
            return $this->noMatch();
        }

        $sameDob = array_values(array_filter(
            $candidates,
            fn (array $c) => ($c['tanggal_lahir'] ?? null) === $tanggalLahir,
        ));

        if (empty($sameDob)) {
            return $this->noMatch();
        }

        $scored = array_map(fn (array $candidate) => $this->scoreCandidate($submitted, $candidate), $sameDob);

        // usort STABIL sejak PHP 8.0 - kandidat dengan skor sama persis
        // akan tetap terurut sesuai urutan kemunculan aslinya di $candidates
        // (deterministik, bukan tergantung urutan internal algoritma sort).
        usort($scored, fn (array $a, array $b) => $b['nama_score'] <=> $a['nama_score']);

        $best = $scored[0];

        return [
            'verdict' => $this->verdictFor($best),
            'candidate' => $best['candidate'],
            'nama_score' => $best['nama_score'],
            'nama_ibu_score' => $best['nama_ibu_score'],
            'no_hp_exact' => $best['no_hp_exact'],
        ];
    }

    protected function scoreCandidate(array $submitted, array $candidate): array
    {
        $namaScore = NameSimilarity::score($submitted['nama'] ?? null, $candidate['nama'] ?? null);

        $namaIbuSubmitted = $submitted['nama_ibu_kandung'] ?? null;
        $namaIbuCandidate = $candidate['nama_ibu_kandung'] ?? null;
        $namaIbuScore = ($namaIbuSubmitted !== null && $namaIbuSubmitted !== '' && $namaIbuCandidate !== null && $namaIbuCandidate !== '')
            ? NameSimilarity::score($namaIbuSubmitted, $namaIbuCandidate)
            : null;

        $submittedHp = IndonesianPhoneNumber::normalize($submitted['no_hp'] ?? null);
        $candidateHp = IndonesianPhoneNumber::normalize($candidate['no_hp'] ?? null);
        $noHpExact = ($submittedHp !== null && $candidateHp !== null) ? $submittedHp === $candidateHp : null;

        return [
            'candidate' => $candidate,
            'nama_score' => $namaScore,
            'nama_ibu_score' => $namaIbuScore,
            'no_hp_exact' => $noHpExact,
        ];
    }

    protected function verdictFor(array $best): PatientMatchVerdict
    {
        $namaScore = $best['nama_score'];

        if ($namaScore >= $this->thresholds['nama_confident_threshold']) {
            return PatientMatchVerdict::Confident;
        }

        if ($namaScore >= $this->thresholds['nama_probable_threshold']) {
            return PatientMatchVerdict::Probable;
        }

        $corroborated = ($best['nama_ibu_score'] !== null && $best['nama_ibu_score'] >= $this->thresholds['nama_ibu_moderate_threshold'])
            || $best['no_hp_exact'] === true;

        if ($namaScore >= $this->thresholds['nama_weak_threshold'] && $corroborated) {
            return PatientMatchVerdict::Probable;
        }

        return PatientMatchVerdict::NoMatch;
    }

    protected function noMatch(): array
    {
        return [
            'verdict' => PatientMatchVerdict::NoMatch,
            'candidate' => null,
            'nama_score' => null,
            'nama_ibu_score' => null,
            'no_hp_exact' => null,
        ];
    }
}
