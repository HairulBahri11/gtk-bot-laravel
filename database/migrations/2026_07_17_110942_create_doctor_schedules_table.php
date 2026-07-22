<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cache lokal dari GET /jadwaldokter (API GTK). Kolom "shift" dihitung
     * otomatis dari jam_mulai saat sync (lihat config/gtk.php shift_windows).
     */
    public function up(): void
    {
        Schema::create('doctor_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('kode_dokter')->index();
            $table->string('kode_poliklinik')->index();
            $table->enum('hari', ['SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT', 'SABTU', 'MINGGU']);
            $table->time('jam_mulai');
            $table->time('jam_selesai');
            $table->enum('shift', ['pagi', 'sore', 'malam']);
            $table->unsignedInteger('kuota_total')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['kode_dokter', 'hari', 'jam_mulai'], 'doctor_schedules_unique_slot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_schedules');
    }
};
