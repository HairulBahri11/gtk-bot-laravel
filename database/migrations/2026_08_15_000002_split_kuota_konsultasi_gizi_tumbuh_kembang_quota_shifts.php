<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot harian dari alokasi konsultasi - pecah jadi dua pool
     * terisolasi (Gizi/Tumbuh Kembang), sama seperti migration serupa di
     * doctor_schedules pada waktu yang sama. Alokasi & pemakaian
     * kuota_konsultasi yang sudah ada dianggap Tumbuh Kembang (keputusan
     * produk saat migrasi ini dibuat), Gizi mulai dari 0 di seluruh baris.
     *
     * kuota_konsultasi_gizi/kuota_konsultasi_tumbuh_kembang berperilaku
     * SAMA seperti kuota_konsultasi dulu (alokasi yang bisa diubah manual
     * admin, tidak pernah ditimpa balik oleh resync - lihat
     * QuotaService::rebuildQuotaShifts()). kuota_terpakai_konsultasi_gizi/
     * kuota_terpakai_konsultasi_tumbuh_kembang juga sama seperti
     * kuota_terpakai_konsultasi dulu (fakta terpakai, dihitung ulang tiap
     * resync).
     */
    public function up(): void
    {
        Schema::table('quota_shifts', function (Blueprint $table) {
            $table->unsignedInteger('kuota_konsultasi_gizi')->default(0)->after('kuota_total');
            $table->unsignedInteger('kuota_terpakai_konsultasi_gizi')->default(0)->after('kuota_terpakai');
            $table->unsignedInteger('kuota_konsultasi_tumbuh_kembang')->default(0)->after('kuota_konsultasi_gizi');
            $table->unsignedInteger('kuota_terpakai_konsultasi_tumbuh_kembang')->default(0)->after('kuota_terpakai_konsultasi_gizi');
        });

        DB::table('quota_shifts')->update([
            'kuota_konsultasi_tumbuh_kembang' => DB::raw('kuota_konsultasi'),
            'kuota_terpakai_konsultasi_tumbuh_kembang' => DB::raw('kuota_terpakai_konsultasi'),
        ]);

        Schema::table('quota_shifts', function (Blueprint $table) {
            $table->dropColumn(['kuota_konsultasi', 'kuota_terpakai_konsultasi']);
        });
    }

    public function down(): void
    {
        Schema::table('quota_shifts', function (Blueprint $table) {
            $table->unsignedInteger('kuota_konsultasi')->default(1)->after('kuota_total');
            $table->unsignedInteger('kuota_terpakai_konsultasi')->default(0)->after('kuota_terpakai');
        });

        DB::table('quota_shifts')->update([
            'kuota_konsultasi' => DB::raw('kuota_konsultasi_tumbuh_kembang'),
            'kuota_terpakai_konsultasi' => DB::raw('kuota_terpakai_konsultasi_tumbuh_kembang'),
        ]);

        Schema::table('quota_shifts', function (Blueprint $table) {
            $table->dropColumn([
                'kuota_konsultasi_gizi',
                'kuota_terpakai_konsultasi_gizi',
                'kuota_konsultasi_tumbuh_kembang',
                'kuota_terpakai_konsultasi_tumbuh_kembang',
            ]);
        });
    }
};
