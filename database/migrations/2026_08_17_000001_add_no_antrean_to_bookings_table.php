<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nomor antrian kedatangan (BUKAN waitlist_position - itu urutan
     * daftar tunggu SEBELUM kuota tersedia, ini urutan panggil SETELAH
     * pasien benar-benar datang) - diisi begitu admin klik "Datang" (lihat
     * AntreanService::confirmArrival()), SATU urutan gabungan per
     * dokter+tanggal+shift terlepas dari jenis_layanan (beda dari
     * waitlist_position yang sengaja dipisah per kategori). Additive,
     * nullable - booking lama/yang belum datang tetap null.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedInteger('no_antrean')->nullable()->after('waitlist_position');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('no_antrean');
        });
    }
};
