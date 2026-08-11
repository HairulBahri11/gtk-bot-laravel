<?php

namespace App\Models;

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

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'kode_dokter', 'kode_dokter');
    }

    public function poliklinik(): BelongsTo
    {
        return $this->belongsTo(Poliklinik::class, 'kode_poliklinik', 'kode_poliklinik');
    }
}
