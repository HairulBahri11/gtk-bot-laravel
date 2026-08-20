<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sumber kebenaran tunggal untuk memetakan nomor WA pengirim -> dokter
     * (dipakai WhatsappWebhookController untuk membedakan pesan dokter dari
     * pesan calon pasien). Format normalisasi sama seperti IndonesianPhoneNumber::normalize()
     * ("08xxxxxxxxxx"). QuotaService::syncDoctors() tidak menulis kolom ini,
     * jadi aman dari resync GTK.
     */
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->string('no_hp')->nullable()->unique()->after('nama_dokter');
        });
    }

    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn('no_hp');
        });
    }
};
