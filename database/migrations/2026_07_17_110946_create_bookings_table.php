<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Antrean": booking/kunjungan pasien, termasuk waitlist dan
     * No-Show management (buffer_shifted_count) - §3 & §6 PRD.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_session_id')->nullable()->constrained('chat_sessions')->nullOnDelete();
            $table->string('no_rm')->index();
            $table->string('no_rawat')->nullable()->index();
            $table->string('no_reg')->nullable();
            $table->string('kode_poliklinik')->index();
            $table->string('kode_dokter')->index();
            $table->date('tanggal_periksa')->index();
            $table->enum('shift', ['pagi', 'sore', 'malam']);
            $table->enum('status', [
                'waitlist',
                'booked',
                'confirmed',
                'arrived',
                'no_show',
                'cancelled',
                'rescheduled',
            ])->default('booked')->index();
            $table->unsignedInteger('waitlist_position')->nullable();
            $table->unsignedInteger('buffer_shifted_count')->default(0);
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
