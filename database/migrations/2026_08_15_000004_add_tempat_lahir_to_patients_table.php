<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formulir pendaftaran STATE_1 sekarang juga menanyakan tempat lahir
     * (digabung dengan tanggal lahir di satu baris isian - lihat
     * AiEngineService::stateOnePrompt()) - sebelumnya field ini tidak pernah
     * ditanyakan sama sekali, GtkApiService::tambahPasien() selalu mengirim
     * "-" sebagai fallback.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('tempat_lahir')->nullable()->after('tanggal_lahir');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('tempat_lahir');
        });
    }
};
