<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Konsultasi" sebagai satu kategori tunggal dipecah jadi dua pool
     * terisolasi - Gizi dan Tumbuh Kembang (lihat App\Enums\JenisLayanan) -
     * masing-masing dokter/poliklinik kini bisa mengalokasikan kuota
     * konsultasi gizi terpisah dari tumbuh kembang, bukan satu angka
     * gabungan. Alokasi kuota_konsultasi yang sudah ada dianggap Tumbuh
     * Kembang (keputusan produk saat migrasi ini dibuat - kategori Gizi
     * baru, mulai dari 0) supaya jadwal yang sudah dikonfigurasi admin
     * tidak hilang/direset ke 0 begitu saja.
     */
    public function up(): void
    {
        Schema::table('doctor_schedules', function (Blueprint $table) {
            $table->unsignedInteger('kuota_konsultasi_gizi')->default(0)->after('kuota_total');
            $table->unsignedInteger('kuota_konsultasi_tumbuh_kembang')->default(0)->after('kuota_konsultasi_gizi');
        });

        DB::table('doctor_schedules')->update([
            'kuota_konsultasi_tumbuh_kembang' => DB::raw('kuota_konsultasi'),
        ]);

        Schema::table('doctor_schedules', function (Blueprint $table) {
            $table->dropColumn('kuota_konsultasi');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_schedules', function (Blueprint $table) {
            $table->unsignedInteger('kuota_konsultasi')->default(1)->after('kuota_total');
        });

        DB::table('doctor_schedules')->update([
            'kuota_konsultasi' => DB::raw('kuota_konsultasi_tumbuh_kembang'),
        ]);

        Schema::table('doctor_schedules', function (Blueprint $table) {
            $table->dropColumn(['kuota_konsultasi_gizi', 'kuota_konsultasi_tumbuh_kembang']);
        });
    }
};
