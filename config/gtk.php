<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shift Bucketing
    |--------------------------------------------------------------------------
    |
    | GET /jadwaldokter dari API GTK hanya mengembalikan jam_mulai/jam_selesai,
    | tanpa field "shift". Sistem mengelompokkan setiap jadwal ke salah satu
    | shift berikut berdasarkan jam_mulai (format 24 jam, "HH:MM").
    |
    */
    'shift_windows' => [
        'pagi' => ['start' => '06:00', 'end' => '14:00'],
        'sore' => ['start' => '14:00', 'end' => '18:00'],
        'malam' => ['start' => '18:00', 'end' => '22:00'],
    ],

    /*
    |--------------------------------------------------------------------------
    | No-Show Buffer
    |--------------------------------------------------------------------------
    |
    | Saat pasien terdeteksi No-Show, sistem menggeser buffer antrean
    | sebanyak N kuota (rentang min-max) sebelum menawarkan shift lain
    | atau mempromosikan pasien waitlist berikutnya.
    |
    */
    'no_show_buffer_min' => (int) env('GTK_NO_SHOW_BUFFER_MIN', 3),
    'no_show_buffer_max' => (int) env('GTK_NO_SHOW_BUFFER_MAX', 5),

    /*
    |--------------------------------------------------------------------------
    | Shift Change Notification Stagger
    |--------------------------------------------------------------------------
    |
    | Saat dokter membatalkan/menunda shift, notifikasi WA ke tiap pasien
    | terdampak dikirim satu per satu dengan jeda random (detik) antar
    | pengiriman supaya tidak kena rate-limit/blokir Meta - lihat
    | AntreanService::cancelShiftAndReschedule()/delayShiftAndNotify().
    |
    */
    'notification_stagger_min_seconds' => (int) env('GTK_NOTIFY_STAGGER_MIN', 3),
    'notification_stagger_max_seconds' => (int) env('GTK_NOTIFY_STAGGER_MAX', 15),

    /*
    |--------------------------------------------------------------------------
    | Reminder Windows
    |--------------------------------------------------------------------------
    */
    'reminder_windows' => [
        'h1' => ['value' => 1, 'unit' => 'day'],
        '3jam' => ['value' => 3, 'unit' => 'hour'],
        '1jam' => ['value' => 1, 'unit' => 'hour'],
    ],

    /*
    |--------------------------------------------------------------------------
    | GTK API Token Cache
    |--------------------------------------------------------------------------
    */
    'token_cache_key' => 'gtk_api_token',
    'token_cache_ttl' => 50 * 60, // detik, sedikit di bawah umumnya masa berlaku token 1 jam

    /*
    |--------------------------------------------------------------------------
    | Pencocokan Identitas Pasien Berlapis
    |--------------------------------------------------------------------------
    |
    | Ambang batas skor kemiripan nama (0-100, lihat NameSimilarity) yang
    | dipakai PatientMatcher untuk memutuskan status pencocokan pasien saat
    | nama yang diketik tidak exact match dengan data GTK/cache lokal - lihat
    | docblock kelas PatientMatcher untuk penjelasan lengkap kenapa
    | nama_ibu/no_hp sengaja TIDAK PERNAH bisa membawa status ke "confident"
    | sendirian (risiko pasien kembar).
    |
    | - nama_confident_threshold: skor nama MINIMAL supaya pasien langsung
    |   dipakai tanpa bertanya ke user (setara "diketik persis benar").
    |   90 dipilih supaya typo 1 huruf pada nama yang cukup panjang
    |   (mis. "Muhamad" vs "Muhammad") tetap lolos diam-diam, tapi nama
    |   pendek dengan 1 huruf beda (mis. "Budi" vs "Budy", skor ~75) TIDAK
    |   lolos diam-diam - nama pendek secara proporsional "terkena" lebih
    |   berat oleh typo yang sama, jadi tetap wajib dikonfirmasi.
    | - nama_probable_threshold: skor nama MINIMAL supaya user ditanya
    |   konfirmasi (walau TANPA penguat nama_ibu/no_hp sama sekali).
    | - nama_weak_threshold: skor nama MINIMAL yang masih layak ditanyakan
    |   ke user, TAPI HANYA kalau ada penguat (nama_ibu mirip ATAU no_hp
    |   persis sama) - di bawah ini dianggap kebetulan/nama lain, walau
    |   tanggal lahir sama persis (bisa saja 2 pasien berbeda kebetulan
    |   lahir di tanggal yang sama).
    | - nama_ibu_moderate_threshold: skor kemiripan nama_ibu_kandung
    |   MINIMAL supaya dianggap "menguatkan" skor nama yang lemah.
    |
    */
    'patient_matching' => [
        'nama_confident_threshold' => (float) env('GTK_MATCH_NAMA_CONFIDENT', 90),
        'nama_probable_threshold' => (float) env('GTK_MATCH_NAMA_PROBABLE', 65),
        'nama_weak_threshold' => (float) env('GTK_MATCH_NAMA_WEAK', 50),
        'nama_ibu_moderate_threshold' => (float) env('GTK_MATCH_IBU_MODERATE', 70),
    ],
];
