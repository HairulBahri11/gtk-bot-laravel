<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// "Kuota background" - §3.1 langkah 4 PRD.
Schedule::command('gtk:sync-quota')->everyTenMinutes()->withoutOverlapping();

// Pengingat Otomatis - §6.B PRD.
Schedule::command('gtk:send-reminders')->everyFifteenMinutes()->withoutOverlapping();

// Manajemen No-Show - §3.2 PRD.
Schedule::command('gtk:process-no-show')->everyFifteenMinutes()->withoutOverlapping();
