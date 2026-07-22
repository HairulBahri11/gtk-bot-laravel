<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cache lokal dari GET /poliklinik (API GTK).
     */
    public function up(): void
    {
        Schema::create('poliklinik', function (Blueprint $table) {
            $table->string('kode_poliklinik')->primary();
            $table->string('nama_poliklinik');
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poliklinik');
    }
};
