<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * status pindah dari enum DB-level (['waitlist','booked','confirmed',
     * 'arrived','no_show','cancelled','rescheduled']) ke string biasa -
     * App\Enums\BookingStatus sekarang punya kasus baru "selesai" (lihat
     * AntreanService::completeVisit()). Pola & alasan PERSIS sama dengan
     * migration jenis_layanan sebelumnya
     * (2026_08_15_000003_split_konsultasi_jenis_layanan_bookings.php) -
     * ->change() tidak menjamin melepas CHECK constraint enum lama di
     * Postgres, string biasa portable di MySQL (test lokal) & Postgres
     * (Supabase) tanpa DDL khusus per-engine. Tidak ada remapping nilai -
     * semua nilai lama tetap apa adanya, hanya memperluas nilai yang
     * diizinkan ke depan. Validitas tetap ditegakkan penuh di level PHP
     * lewat BookingStatus cast + ::from()/::tryFrom().
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('status_new')->default('booked')->after('status');
        });

        DB::table('bookings')->update(['status_new' => DB::raw('status')]);

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->renameColumn('status_new', 'status');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('status_old', [
                'waitlist', 'booked', 'confirmed', 'arrived', 'no_show', 'cancelled', 'rescheduled',
            ])->default('booked')->after('status');
        });

        // Lossy secara sengaja - booking "selesai" tidak punya padanan di
        // enum lama, dipetakan balik ke "arrived" (state paling dekat
        // maknanya: pasien sudah datang, cuma status "selesai"-nya yang
        // hilang).
        DB::table('bookings')->update([
            'status_old' => DB::raw("CASE WHEN status = 'selesai' THEN 'arrived' ELSE status END"),
        ]);

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->renameColumn('status_old', 'status');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->index('status');
        });
    }
};
