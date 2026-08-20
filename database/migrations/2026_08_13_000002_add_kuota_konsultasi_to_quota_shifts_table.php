<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot harian dari alokasi konsultasi (lihat migration serupa di
     * doctor_schedules untuk alasan kuota_pemeriksaan tidak disimpan
     * terpisah). Dua kolom di sini punya perlakuan BERBEDA saat resync
     * (QuotaService::rebuildQuotaShifts(), tiap 10 menit via gtk:sync-quota):
     *
     * - kuota_konsultasi: ALOKASI yang bisa diubah manual oleh admin lewat
     *   dashboard (mis. kuota pemeriksaan sepi, geser sebagian ke
     *   konsultasi) - HARUS diperlakukan sama seperti kolom status/
     *   delay_minutes/reason yang ditambahkan migration
     *   2026_08_12_000002_add_status_to_quota_shifts_table.php: diisi nilai
     *   awal saat baris QuotaShift PERTAMA KALI dibuat (dari template
     *   doctor_schedules), tapi TIDAK PERNAH ditimpa balik oleh resync
     *   berikutnya - kalau ini luput diikutkan ke $updateColumns saat
     *   upsert, perubahan admin akan hilang diam-diam setiap 10 menit.
     * - kuota_terpakai_konsultasi: FAKTA terpakai, dihitung ulang dari
     *   COUNT booking aktif setiap resync - sama seperti kuota_terpakai
     *   yang sudah ada, BUKAN nilai yang diedit admin secara langsung.
     */
    public function up(): void
    {
        Schema::table('quota_shifts', function (Blueprint $table) {
            $table->unsignedInteger('kuota_konsultasi')->default(1)->after('kuota_total');
            $table->unsignedInteger('kuota_terpakai_konsultasi')->default(0)->after('kuota_terpakai');
        });
    }

    public function down(): void
    {
        Schema::table('quota_shifts', function (Blueprint $table) {
            $table->dropColumn(['kuota_konsultasi', 'kuota_terpakai_konsultasi']);
        });
    }
};
