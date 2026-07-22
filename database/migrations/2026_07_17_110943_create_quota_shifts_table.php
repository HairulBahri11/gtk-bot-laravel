<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Kuota background": snapshot kuota real-time per dokter+tanggal+shift.
     * Diisi/diperbarui oleh scheduled command gtk:sync-quota supaya
     * pengecekan kuota saat chat berlangsung tidak selalu hit API GTK.
     */
    public function up(): void
    {
        Schema::create('quota_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('kode_dokter')->index();
            $table->string('kode_poliklinik')->index();
            $table->date('tanggal')->index();
            $table->enum('shift', ['pagi', 'sore', 'malam']);
            $table->unsignedInteger('kuota_total')->default(0);
            $table->unsignedInteger('kuota_terpakai')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['kode_dokter', 'tanggal', 'shift'], 'quota_shifts_unique_slot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quota_shifts');
    }
};
