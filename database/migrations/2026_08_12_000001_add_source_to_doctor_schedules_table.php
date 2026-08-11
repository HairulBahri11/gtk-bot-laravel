<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membedakan baris jadwal hasil sinkronisasi GTK ('gtk', default) dari
     * jadwal yang diinput manual (mis. dr. Retno Wulandari, SpA - GTK
     * /jadwaldokter tidak punya data untuk dokter ini). QuotaService::syncSchedules()
     * melewati baris 'manual' supaya sync GTK tidak pernah menimpanya.
     */
    public function up(): void
    {
        Schema::table('doctor_schedules', function (Blueprint $table) {
            $table->enum('source', ['gtk', 'manual'])->default('gtk')->after('kuota_total');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_schedules', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
