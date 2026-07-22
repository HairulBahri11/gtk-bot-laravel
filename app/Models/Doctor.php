<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Doctor extends Model
{
    protected $table = 'doctors';

    protected $primaryKey = 'kode_dokter';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'kode_dokter',
        'nama_dokter',
        'kode_poliklinik',
        'is_active',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function poliklinik(): BelongsTo
    {
        return $this->belongsTo(Poliklinik::class, 'kode_poliklinik', 'kode_poliklinik');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(DoctorSchedule::class, 'kode_dokter', 'kode_dokter');
    }
}
