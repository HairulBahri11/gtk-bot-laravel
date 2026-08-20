<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Status harian per shift (buka/dibatalkan/delay dokter) - level instance
     * per tanggal, bukan template mingguan (doctor_schedules). QuotaService::
     * rebuildQuotaShifts() upsert hanya menyentuh kode_poliklinik/kuota_total/
     * kuota_terpakai/last_synced_at, jadi kolom ini aman dari resync GTK.
     */
    public function up(): void
    {
        Schema::table('quota_shifts', function (Blueprint $table) {
            $table->enum('status', ['open', 'cancelled', 'delayed'])->default('open')->after('kuota_terpakai');
            $table->unsignedInteger('delay_minutes')->nullable()->after('status');
            $table->string('reason')->nullable()->after('delay_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('quota_shifts', function (Blueprint $table) {
            $table->dropColumn(['status', 'delay_minutes', 'reason']);
        });
    }
};
