<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    protected $table = 'patients';

    protected $primaryKey = 'no_rm';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'no_rm',
        'nama',
        'jk',
        'tanggal_lahir',
        'nama_ibu_kandung',
        'no_hp',
        'alamat',
        'nik',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
            'last_synced_at' => 'datetime',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'no_rm', 'no_rm');
    }

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class, 'no_rm', 'no_rm');
    }
}
