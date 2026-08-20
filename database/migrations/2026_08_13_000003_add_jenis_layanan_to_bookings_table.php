<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kategori kuota yang dipakai booking ini (lihat App\Enums\JenisLayanan
     * & migration serupa di quota_shifts/doctor_schedules) - WAJIB disimpan
     * per booking (bukan cuma dihitung dari poliklinik) supaya
     * cancelBooking()/markNoShow()/promoteWaitlist() tahu persis pool mana
     * yang harus dilepas/diisi kembali saat booking ini berubah status.
     * Default 'pemeriksaan' untuk booking lama sebelum kolom ini ada (14
     * dari 15 kuota per shift secara historis memang untuk pemeriksaan/
     * imunisasi, bukan konsultasi).
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('jenis_layanan', ['pemeriksaan', 'konsultasi'])->default('pemeriksaan')->after('shift');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('jenis_layanan');
        });
    }
};
