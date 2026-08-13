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
        'kuota_konsultasi',
        'kuota_terpakai_konsultasi',
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
     * Alokasi konsultasi yang TERSIMPAN (kuota_konsultasi) bisa jadi lebih
     * besar dari kuota_total kalau GTK belakangan mengecilkan total shift
     * ini (sync tidak pernah menyentuh kuota_konsultasi, lihat migration
     * 2026_08_13_000002) - dibatasi di sini saat DIBACA supaya kedua pool
     * gabungan tidak pernah melebihi kuota_total yang sesungguhnya, tanpa
     * diam-diam menimpa nilai yang tersimpan/terlihat di dashboard.
     */
    protected function kuotaKonsultasiEfektif(): int
    {
        return min($this->kuota_konsultasi, $this->kuota_total);
    }

    public function kuotaFor(JenisLayanan $jenis): int
    {
        return $jenis === JenisLayanan::Konsultasi
            ? $this->kuotaKonsultasiEfektif()
            : max(0, $this->kuota_total - $this->kuotaKonsultasiEfektif());
    }

    public function terpakaiFor(JenisLayanan $jenis): int
    {
        return $jenis === JenisLayanan::Konsultasi
            ? $this->kuota_terpakai_konsultasi
            : max(0, $this->kuota_terpakai - $this->kuota_terpakai_konsultasi);
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

    public function getKuotaTersisaKonsultasiAttribute(): int
    {
        return $this->tersisaFor(JenisLayanan::Konsultasi);
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
