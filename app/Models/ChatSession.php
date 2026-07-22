<?php

namespace App\Models;

use App\Enums\ChatState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSession extends Model
{
    protected $fillable = [
        'chat_id',
        'state',
        'step',
        'context',
        'no_rm',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => ChatState::class,
            'context' => 'array',
            'last_message_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'no_rm', 'no_rm');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class, 'chat_id', 'chat_id');
    }

    public function latestBooking(): HasMany
    {
        return $this->bookings()->latest();
    }
}
