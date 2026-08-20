<?php

namespace App\Console\Commands;

use App\Services\Quota\QuotaService;
use Illuminate\Console\Command;

/**
 * Bangun ulang snapshot kuota harian (quota_shifts) dari jadwal dashboard
 * (doctor_schedules, source='manual') untuk N hari ke depan - TIDAK
 * memanggil API GTK sama sekali, murni proyeksi lokal (lihat
 * QuotaService::rebuildQuotaShifts()).
 *
 * Sengaja dipisah dari gtk:sync-quota (yang sebelumnya menjadwalkan method
 * ini sebagai bagian dari sinkronisasi GTK) supaya snapshot kuota tetap
 * terbangun otomatis walau seluruh panggilan ke GTK untuk keperluan
 * jadwal/kuota/poliklinik/dokter sudah dihentikan dari scheduler - tanpa
 * command ini berjalan berkala, tanggal-tanggal mendatang tidak akan pernah
 * punya baris quota_shifts (dipakai QuotaService::hasAvailability() dkk
 * saat booking).
 *
 * Default --days dinaikkan dari 14 ke 60 - dengan 14 hari, memilih tanggal
 * >2 minggu ke depan di dashboard Jadwal Dokter (mis. ?tanggal=...) selalu
 * menampilkan "Kuota untuk tanggal ini belum tersinkron" karena baris
 * quota_shifts-nya memang belum pernah dibangun. Tanggal di luar jendela
 * ini (jarang, tapi bisa terjadi) tetap ditangani lewat
 * QuotaService::resolveOrCreateQuota() (dipanggil on-demand oleh tombol
 * "Ubah Kuota" per tanggal), bukan cuma mengandalkan angka ini besar.
 */
class RebuildQuotaShifts extends Command
{
    protected $signature = 'quota:rebuild-shifts {--days=60 : Jumlah hari ke depan yang dibangun ulang}';

    protected $description = 'Bangun ulang snapshot kuota harian dari jadwal dashboard (tanpa memanggil API GTK)';

    public function handle(QuotaService $quota): int
    {
        $this->info('Membangun ulang snapshot kuota dari jadwal dashboard...');

        $quota->rebuildQuotaShifts((int) $this->option('days'));

        $this->info('Selesai.');

        return self::SUCCESS;
    }
}
