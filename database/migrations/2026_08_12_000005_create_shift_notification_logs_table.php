<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit + idempotency guard untuk NotifyShiftChangeJob - persis pola
     * reminder_logs (unique per booking+jenis event) supaya job yang retry
     * (mis. gagal lalu diproses ulang oleh queue) tidak mengirim WA dobel ke
     * pasien yang sama untuk event yang sama.
     */
    public function up(): void
    {
        Schema::create('shift_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->enum('event', ['rescheduled', 'cancelled_no_alternative', 'delayed']);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['booking_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_notification_logs');
    }
};
