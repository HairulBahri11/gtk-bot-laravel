<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * jenis_layanan pindah dari enum DB-level (['pemeriksaan','konsultasi'])
     * ke string biasa - App\Enums\JenisLayanan sekarang punya 3 kasus
     * (Pemeriksaan/KonsultasiGizi/KonsultasiTumbuhKembang, lihat migration
     * serupa di quota_shifts/doctor_schedules pada waktu yang sama), dan
     * mengubah SET NILAI enum native berbeda caranya antara MySQL (test
     * lokal, lihat phpunit.xml) dan Postgres (Supabase, dev/prod) - string
     * biasa portable di keduanya tanpa DDL khusus per-engine. Validitas
     * nilai tetap sepenuhnya ditegakkan di level PHP lewat JenisLayanan
     * cast + ::from()/::tryFrom() di seluruh QuotaService/AntreanService/
     * ProcessIncomingWhatsappMessage - constraint DB lama redundan, bukan
     * satu-satunya penjaga.
     *
     * Pola "kolom baru -> copy data -> drop kolom lama -> rename" dipakai
     * (bukan ->change() langsung) supaya CHECK constraint enum lama di
     * Postgres PASTI ikut hilang (drop column menghapus constraint yang
     * menempel padanya) - ->change() mengubah tipe kolom tapi tidak
     * dijamin melepas CHECK constraint terpisah yang sudah ada.
     *
     * Data lama 'konsultasi' (2 booking nyata saat migrasi ini dibuat)
     * dipetakan ke 'konsultasi_tumbuh_kembang' - keputusan produk yang
     * sama dengan migration quota_shifts/doctor_schedules di atas.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('jenis_layanan_new')->default('pemeriksaan')->after('jenis_layanan');
        });

        DB::table('bookings')->update([
            'jenis_layanan_new' => DB::raw("CASE WHEN jenis_layanan = 'konsultasi' THEN 'konsultasi_tumbuh_kembang' ELSE jenis_layanan END"),
        ]);

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('jenis_layanan');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->renameColumn('jenis_layanan_new', 'jenis_layanan');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('jenis_layanan_old', ['pemeriksaan', 'konsultasi'])->default('pemeriksaan')->after('jenis_layanan');
        });

        // Lossy secara sengaja - Gizi & Tumbuh Kembang digabung balik jadi
        // satu nilai 'konsultasi' (enum lama tidak punya ruang untuk
        // membedakan keduanya).
        DB::table('bookings')->update([
            'jenis_layanan_old' => DB::raw("CASE WHEN jenis_layanan IN ('konsultasi_gizi', 'konsultasi_tumbuh_kembang') THEN 'konsultasi' ELSE jenis_layanan END"),
        ]);

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('jenis_layanan');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->renameColumn('jenis_layanan_old', 'jenis_layanan');
        });
    }
};
