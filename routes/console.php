<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// "Kuota background" - §3.1 langkah 4 PRD.
Schedule::command('gtk:sync-quota')->everyTenMinutes()->withoutOverlapping();

// Pengingat Otomatis (H-1 hari/3 jam/1 jam) - dipindah ke sistem baru berbasis
// tabel kunjungan_reminder/reminder_log di Supabase (lihat
// supabase/migrations/20260729120000_kunjungan_reminder.sql). Sync & dispatch
// dipisah jadi 2 command supaya frekuensinya bisa beda: sync (panggil GTK
// API) cukup jarang, dispatch (evaluasi window + kirim WA) harus lebih sering
// supaya window 3 jam/1 jam tidak kelewat. gtk:send-reminders (lama)
// dinonaktifkan supaya reminder tidak terkirim dobel dari 2 sistem berbeda.
Schedule::command('gtk:sync-kunjungan-reminder')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('gtk:dispatch-kunjungan-reminder')->everyFiveMinutes()->withoutOverlapping();

// Manajemen No-Show - §3.2 PRD.
Schedule::command('gtk:process-no-show')->everyFifteenMinutes()->withoutOverlapping();
