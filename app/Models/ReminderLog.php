<?php

namespace App\Models;

use App\Enums\ReminderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'booking_id',
        'type',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ReminderType::class,
            'sent_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
