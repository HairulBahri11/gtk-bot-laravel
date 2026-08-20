<?php

namespace App\Enums;

/**
 * Hasil evaluasi PatientMatcher::evaluate() - lihat docblock kelas itu
 * untuk aturan lengkap kapan masing-masing status berlaku.
 */
enum PatientMatchVerdict: string
{
    /**
     * Cukup yakin untuk langsung dipakai TANPA bertanya ke user - perilaku
     * identik dengan sebelum fitur pencocokan berlapis ini ada.
     */
    case Confident = 'confident';

    /**
     * Kemungkinan cocok, tapi tidak cukup yakin untuk langsung dipakai
     * ATAU langsung dianggap pasien baru - WAJIB konfirmasi eksplisit ke
     * user dulu (lihat handleProbableMatch() di ProcessIncomingWhatsappMessage).
     */
    case Probable = 'probable';

    /**
     * Tidak ada kandidat yang cukup mirip - lanjutkan sebagai pendaftaran
     * pasien baru, persis seperti perilaku lama.
     */
    case NoMatch = 'no_match';
}
