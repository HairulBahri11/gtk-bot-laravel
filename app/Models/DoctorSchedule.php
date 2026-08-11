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

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'kode_dokter', 'kode_dokter');
    }

    public function poliklinik(): BelongsTo
    {
        return $this->belongsTo(Poliklinik::class, 'kode_poliklinik', 'kode_poliklinik');
    }
}
