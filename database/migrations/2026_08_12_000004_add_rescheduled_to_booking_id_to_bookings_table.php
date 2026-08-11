<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menautkan booking lama (status 'rescheduled') ke booking baru yang
     * dibuat otomatis oleh AntreanService::cancelShiftAndReschedule() saat
     * dokter membatalkan shift & pasien digeser ke shift berikutnya di hari
     * yang sama - dipakai dashboard/notifikasi untuk telusur cascading.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('rescheduled_to_booking_id')->nullable()->after('cancel_reason')
                ->constrained('bookings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rescheduled_to_booking_id');
        });
    }
};
