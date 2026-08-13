<?php

namespace App\Enums;

/**
 * Kategori kuota per shift (lihat QuotaShift::kuota_konsultasi & docblock
 * migration terkait) - setiap shift dokter membagi kuota_total-nya jadi dua
 * pool terisolasi: Pemeriksaan/Imunisasi (default 14) dan Konsultasi
 * (default 1). Pasien WhatsApp WAJIB ditanya secara eksplisit termasuk yang
 * mana (tidak pernah diturunkan otomatis dari poliklinik/keluhan - lihat
 * ProcessIncomingWhatsappMessage STATE_1 "jenis_layanan_dijawab").
 */
enum JenisLayanan: string
{
    case Pemeriksaan = 'pemeriksaan';
    case Konsultasi = 'konsultasi';

    public function label(): string
    {
        return match ($this) {
            self::Pemeriksaan => 'Pemeriksaan/Imunisasi',
            self::Konsultasi => 'Konsultasi',
        };
    }
}
