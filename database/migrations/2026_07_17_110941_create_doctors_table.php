<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cache lokal dari GET /dokteraktif (API GTK).
     */
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            $table->string('kode_dokter')->primary();
            $table->string('nama_dokter');
            $table->string('kode_poliklinik')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
