<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Poliklinik extends Model
{
    protected $table = 'poliklinik';

    protected $primaryKey = 'kode_poliklinik';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'kode_poliklinik',
        'nama_poliklinik',
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
}
