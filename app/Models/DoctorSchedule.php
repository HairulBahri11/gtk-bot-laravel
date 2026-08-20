<?php

namespace App\Models;

use App\Enums\Shift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorSchedule extends Model
{
    protected $fillable = [
        'kode_dokter',
        'kode_poliklinik',
        'hari',
        'jam_mulai',
        'jam_selesai',
        'shift',
        'kuota_total',
        'kuota_konsultasi_gizi',
        'kuota_konsultasi_tumbuh_kembang',
        'source',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'shift' => Shift::class,
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Template default alokasi pemeriksaan - QuotaService::rebuildQuotaShifts()
     * menyalin kedua kolom kuota_konsultasi_* ini ke QuotaShift HANYA saat
     * baris snapshot pertama kali dibuat (lihat QuotaShift::kuotaFor() untuk
     * turunan yang sesungguhnya dipakai saat pengecekan ketersediaan).
     * Kedua alokasi konsultasi digabung dulu (clamped ke kuota_total) supaya
     * konsisten dengan cara QuotaShift::kuotaFor() menghitung Pemeriksaan.
     */
    public function getKuotaPemeriksaanAttribute(): int
    {
        $konsultasi = min($this->kuota_konsultasi_gizi + $this->kuota_konsultasi_tumbuh_kembang, $this->kuota_total);

        return max(0, $this->kuota_total - $konsultasi);
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
