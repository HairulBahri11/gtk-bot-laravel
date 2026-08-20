<?php

use App\Http\Controllers\AntreanController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DoctorController;
use App\Http\Controllers\JadwalDokterController;
use App\Http\Controllers\KuotaController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\PoliklinikController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Tab utama 1: Overview.
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Tab utama 2: Pre-Layanan (sub-tab: Antrean & Monitoring / Kuota).
    // Jadwal Dokter sengaja dipindah ke top navbar-nya sendiri (lihat
    // AuthenticatedLayout) - bukan lagi sub-tab Pre-Layanan, walau URL-nya
    // tetap di bawah prefix /pre-layanan (tidak perlu pindah route, cukup
    // navigasinya saja). Jadwal & kuota sekarang diatur manual dari
    // dashboard Jadwal Dokter, bukan dari sync GTK - lihat catatan di
    // JadwalDokterController & filter source='manual' di seluruh query
    // DoctorSchedule yang menentukan booking.
    Route::redirect('/pre-layanan', '/pre-layanan/antrean');

    Route::prefix('pre-layanan')->group(function () {
        Route::get('/kuota', [KuotaController::class, 'index'])->name('kuota.index');
        Route::patch('/kuota/{quotaShift}/konsultasi', [KuotaController::class, 'updateKonsultasi'])->name('kuota.update-konsultasi');

        Route::get('/antrean', [AntreanController::class, 'index'])->name('antrean.index');
        Route::post('/antrean/{booking}/confirm-arrival', [AntreanController::class, 'confirmArrival'])->name('antrean.confirm-arrival');
        Route::post('/antrean/{booking}/complete', [AntreanController::class, 'complete'])->name('antrean.complete');
        Route::post('/antrean/{booking}/no-show', [AntreanController::class, 'noShow'])->name('antrean.no-show');
        Route::post('/antrean/{booking}/cancel', [AntreanController::class, 'cancel'])->name('antrean.cancel');

        // Endpoint JSON untuk modal transkrip chat di halaman Antrean - lihat
        // MonitoringController.
        Route::get('/monitoring/{chatSession}', [MonitoringController::class, 'show'])->name('monitoring.show');

        Route::get('/jadwal', [JadwalDokterController::class, 'index'])->name('jadwal.index');
        Route::post('/jadwal', [JadwalDokterController::class, 'store'])->name('jadwal.store');
        Route::put('/jadwal/{doctorSchedule}', [JadwalDokterController::class, 'update'])->name('jadwal.update');
        Route::delete('/jadwal/{doctorSchedule}', [JadwalDokterController::class, 'destroy'])->name('jadwal.destroy');
        Route::post('/jadwal/cancel-shift', [JadwalDokterController::class, 'cancelShift'])->name('jadwal.cancel-shift');
        Route::post('/jadwal/delay-shift', [JadwalDokterController::class, 'delayShift'])->name('jadwal.delay-shift');
        Route::post('/jadwal/reopen-shift', [JadwalDokterController::class, 'reopenShift'])->name('jadwal.reopen-shift');
        Route::post('/jadwal/update-kuota-tanggal', [JadwalDokterController::class, 'updateKuotaTanggal'])->name('jadwal.update-kuota-tanggal');

        // Master data poliklinik - murni dikelola dari sini, tidak lagi
        // disinkronkan dari API GTK (lihat docblock PoliklinikController).
        Route::get('/poliklinik', [PoliklinikController::class, 'index'])->name('poliklinik.index');
        Route::post('/poliklinik', [PoliklinikController::class, 'store'])->name('poliklinik.store');
        Route::put('/poliklinik/{poliklinik}', [PoliklinikController::class, 'update'])->name('poliklinik.update');

        // Master data dokter - murni dikelola dari sini, tidak lagi
        // disinkronkan dari API GTK (lihat docblock DoctorController).
        Route::get('/dokter', [DoctorController::class, 'index'])->name('dokter.index');
        Route::post('/dokter', [DoctorController::class, 'store'])->name('dokter.store');
        Route::put('/dokter/{doctor}', [DoctorController::class, 'update'])->name('dokter.update');
    });

    // Tab utama 3 & 4: belum dibangun - placeholder "Segera Hadir".
    Route::get('/layanan', fn () => Inertia::render('ComingSoon', [
        'title' => 'Layanan',
        'description' => 'Modul pemeriksaan & pelaksanaan layanan (SOAP, tanda vital) sedang dalam pengembangan.',
    ]))->name('layanan.index');

    Route::get('/post-layanan', fn () => Inertia::render('ComingSoon', [
        'title' => 'Post-Layanan',
        'description' => 'Modul tindak lanjut pasca-kunjungan (follow-up, evaluasi) sedang dalam pengembangan.',
    ]))->name('post-layanan.index');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
