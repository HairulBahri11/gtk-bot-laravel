<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cache lokal pasien hasil GET /caripasien atau POST /tambahpasien.
     */
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->string('no_rm')->primary();
            $table->string('nama');
            $table->enum('jk', ['LAKI-LAKI', 'PEREMPUAN'])->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('nama_ibu_kandung')->nullable();
            $table->string('no_hp')->nullable();
            $table->string('alamat')->nullable();
            $table->string('nik')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['nama', 'tanggal_lahir']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
