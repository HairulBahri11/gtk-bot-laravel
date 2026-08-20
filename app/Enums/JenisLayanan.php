<?php

namespace App\Enums;

/**
 * Kategori kuota per shift (lihat QuotaShift kolom kuota_konsultasi_gizi/
 * kuota_konsultasi_tumbuh_kembang & docblock migration terkait) - setiap
 * shift dokter membagi kuota_total-nya jadi TIGA pool terisolasi: Pemeriksaan
 * (Periksa Sakit + Imunisasi digabung satu pool), Konsultasi Gizi, dan
 * Konsultasi Tumbuh Kembang. Konsultasi Gizi/Tumbuh Kembang HANYA untuk anak
 * SEHAT - anak sakit dengan keluhan bernuansa gizi/tumbuh kembang tetap masuk
 * Pemeriksaan (lihat AiEngineService::stateOnePrompt() aturan
 * gizi/tumbuh-kembang vs sakit). Pasien WhatsApp WAJIB ditanya secara
 * eksplisit termasuk yang mana (tidak pernah diturunkan otomatis dari
 * poliklinik/keluhan - lihat ProcessIncomingWhatsappMessage STATE_1
 * "jenis_layanan_dijawab").
 */
enum JenisLayanan: string
{
    case Pemeriksaan = 'pemeriksaan';
    case KonsultasiGizi = 'konsultasi_gizi';
    case KonsultasiTumbuhKembang = 'konsultasi_tumbuh_kembang';

    public function label(): string
    {
        return match ($this) {
            self::Pemeriksaan => 'Pemeriksaan Sakit/Imunisasi',
            self::KonsultasiGizi => 'Konsultasi Gizi',
            self::KonsultasiTumbuhKembang => 'Konsultasi Tumbuh Kembang',
        };
    }
}
