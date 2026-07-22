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
];
