<?php

namespace App\Models;

use App\Enums\JenisLayanan;
use App\Enums\Shift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotaShift extends Model
{
    protected $fillable = [
        'kode_dokter',
        'kode_poliklinik',
        'tanggal',
        'shift',
        'kuota_total',
        'kuota_terpakai',
        'kuota_konsultasi_gizi',
        'kuota_terpakai_konsultasi_gizi',
        'kuota_konsultasi_tumbuh_kembang',
        'kuota_terpakai_konsultasi_tumbuh_kembang',
        'status',
        'delay_minutes',
        'reason',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'shift' => Shift::class,
            'tanggal' => 'date',
            'last_synced_at' => 'datetime',
        ];
    }

    public function getKuotaTersisaAttribute(): int
    {
        return max(0, $this->kuota_total - $this->kuota_terpakai);
    }

    /**
     * Alokasi konsultasi gizi yang TERSIMPAN bisa jadi lebih besar dari
     * kuota_total kalau GTK belakangan mengecilkan total shift ini (sync
     * tidak pernah menyentuh kolom alokasi ini, lihat migration
     * 2026_08_15_000002) - dibatasi di sini saat DIBACA supaya SEMUA pool
     * gabungan (gizi + tumbuh kembang) tidak pernah melebihi kuota_total
     * yang sesungguhnya, tanpa diam-diam menimpa nilai yang tersimpan/
     * terlihat di dashboard.
     */
    protected function kuotaKonsultasiGiziEfektif(): int
    {
        return max(0, min($this->kuota_konsultasi_gizi, $this->kuota_total));
    }

    /**
     * Sisa kuota_total SETELAH gizi mengambil bagiannya - gizi didahulukan
     * secara arbitrer tapi konsisten (sama seperti urutan kolom di
     * migration) supaya kedua alokasi konsultasi digabung tidak pernah
     * melebihi kuota_total, persis seperti kuotaKonsultasiGiziEfektif().
     */
    protected function kuotaKonsultasiTumbuhKembangEfektif(): int
    {
        return max(0, min($this->kuota_konsultasi_tumbuh_kembang, $this->kuota_total - $this->kuotaKonsultasiGiziEfektif()));
    }

    public function kuotaFor(JenisLayanan $jenis): int
    {
        return match ($jenis) {
            JenisLayanan::KonsultasiGizi => $this->kuotaKonsultasiGiziEfektif(),
            JenisLayanan::KonsultasiTumbuhKembang => $this->kuotaKonsultasiTumbuhKembangEfektif(),
            JenisLayanan::Pemeriksaan => max(0, $this->kuota_total - $this->kuotaKonsultasiGiziEfektif() - $this->kuotaKonsultasiTumbuhKembangEfektif()),
        };
    }

    public function terpakaiFor(JenisLayanan $jenis): int
    {
        return match ($jenis) {
            JenisLayanan::KonsultasiGizi => $this->kuota_terpakai_konsultasi_gizi,
            JenisLayanan::KonsultasiTumbuhKembang => $this->kuota_terpakai_konsultasi_tumbuh_kembang,
            JenisLayanan::Pemeriksaan => max(0, $this->kuota_terpakai - $this->kuota_terpakai_konsultasi_gizi - $this->kuota_terpakai_konsultasi_tumbuh_kembang),
        };
    }

    public function tersisaFor(JenisLayanan $jenis): int
    {
        return max(0, $this->kuotaFor($jenis) - $this->terpakaiFor($jenis));
    }

    public function getKuotaPemeriksaanAttribute(): int
    {
        return $this->kuotaFor(JenisLayanan::Pemeriksaan);
    }

    public function getKuotaTerpakaiPemeriksaanAttribute(): int
    {
        return $this->terpakaiFor(JenisLayanan::Pemeriksaan);
    }

    public function getKuotaTersisaPemeriksaanAttribute(): int
    {
        return $this->tersisaFor(JenisLayanan::Pemeriksaan);
    }

    public function getKuotaTersisaKonsultasiGiziAttribute(): int
    {
        return $this->tersisaFor(JenisLayanan::KonsultasiGizi);
    }

    public function getKuotaTersisaKonsultasiTumbuhKembangAttribute(): int
    {
        return $this->tersisaFor(JenisLayanan::KonsultasiTumbuhKembang);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'kode_dokter', 'kode_dokter');
    }

    public function poliklinik(): BelongsTo
    {
        return $this->belongsTo(Poliklinik::class, 'kode_poliklinik', 'kode_poliklinik');
    }
}
