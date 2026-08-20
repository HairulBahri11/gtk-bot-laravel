<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alokasi default (template mingguan) untuk kuota "konsultasi" - dulu
     * satu shift cuma punya satu angka kuota_total yang dipakai bersama
     * untuk semua tujuan kunjungan. kuota_pemeriksaan TIDAK disimpan
     * terpisah - selalu diturunkan sebagai (kuota_total - kuota_konsultasi),
     * lihat DoctorSchedule::getKuotaPemeriksaanAttribute() - supaya kuota
     * dari sync GTK (yang cuma tahu satu angka total) tetap jadi sumber
     * kebenaran utama, alokasi konsultasi murni "dipotong" darinya.
     */
    public function up(): void
    {
        Schema::table('doctor_schedules', function (Blueprint $table) {
            $table->unsignedInteger('kuota_konsultasi')->default(1)->after('kuota_total');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_schedules', function (Blueprint $table) {
            $table->dropColumn('kuota_konsultasi');
        });
    }
};
