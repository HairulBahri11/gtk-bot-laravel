<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// "Kuota background" - §3.1 langkah 4 PRD. gtk:sync-quota SENGAJA tidak lagi
// dijadwalkan di sini - jadwal, kuota, poliklinik, dan dokter sekarang
// sepenuhnya dikelola manual dari dashboard, tidak perlu sinkronisasi
// otomatis dari GTK lagi (lihat QuotaService::syncFromGtk()). Command itu
// masih ada & bisa dijalankan manual (`php artisan gtk:sync-quota`) kalau
// suatu saat perlu refresh data dokter/poliklinik dari GTK.
//
// quota:rebuild-shifts TETAP dijadwalkan - method itu TIDAK memanggil GTK
// sama sekali, murni proyeksi lokal dari jadwal dashboard (doctor_schedules)
// ke snapshot harian (quota_shifts), dan wajib tetap jalan berkala supaya
// tanggal-tanggal mendatang punya data kuota.
Schedule::command('quota:rebuild-shifts')->everyTenMinutes()->withoutOverlapping();

// Pengingat Otomatis (H-1 hari/3 jam/1 jam) - berbasis tabel
// kunjungan_reminder/reminder_log di Supabase (lihat supabase/migrations/
// 20260729120000_kunjungan_reminder.sql). gtk:sync-kunjungan-reminder
// SENGAJA tidak lagi dijadwalkan di sini - kunjungan_reminder sekarang diisi
// LANGSUNG oleh AntreanService (lihat App\Services\Reminder\
// KunjunganReminderService::upsertForBooking()/cancelForBooking(), dipanggil
// dari createBooking()/promoteWaitlist()/moveBookingToShift()/cancelBooking()/
// markNoShow()) begitu booking di aplikasi ini sendiri berubah status, BUKAN
// lagi disinkronkan periodik dari GTK - jam_kunjungan sekarang mengikuti
// jam_mulai jadwal dokter yang benar-benar dipilih pasien, bukan apa pun yang
// dilaporkan endpoint reminderkunjungan GTK. Command sync itu sendiri masih
// ada (app/Console/Commands/SyncKunjunganReminder.php) tapi dinonaktifkan -
// sama seperti gtk:send-reminders (lama) sebelumnya, JANGAN diaktifkan lagi
// tanpa mencabut pemanggilan KunjunganReminderService di atas dulu, supaya
// tidak ada 2 sistem yang berebut menulis baris yang sama.
Schedule::command('gtk:dispatch-kunjungan-reminder')->everyFiveMinutes()->withoutOverlapping();

// Manajemen No-Show - §3.2 PRD.
Schedule::command('gtk:process-no-show')->everyFifteenMinutes()->withoutOverlapping();
