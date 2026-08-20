<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\JenisLayanan;
use App\Enums\Shift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    protected $fillable = [
        'chat_session_id',
        'no_rm',
        'no_rawat',
        'no_reg',
        'kode_poliklinik',
        'kode_dokter',
        'tanggal_periksa',
        'shift',
        'jenis_layanan',
        'status',
        'waitlist_position',
        'no_antrean',
        'buffer_shifted_count',
        'cancel_reason',
        'rescheduled_to_booking_id',
    ];

    protected function casts(): array
    {
        return [
            'shift' => Shift::class,
            'jenis_layanan' => JenisLayanan::class,
            'status' => BookingStatus::class,
            'tanggal_periksa' => 'date',
        ];
    }

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'no_rm', 'no_rm');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'kode_dokter', 'kode_dokter');
    }

    public function poliklinik(): BelongsTo
    {
        return $this->belongsTo(Poliklinik::class, 'kode_poliklinik', 'kode_poliklinik');
    }

    public function reminderLogs(): HasMany
    {
        return $this->hasMany(ReminderLog::class);
    }

    public function rescheduledTo(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'rescheduled_to_booking_id');
    }
}
