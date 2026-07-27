<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'waha' => [
        'url' => rtrim((string) env('WAHA_URL', 'http://localhost:3000'), '/'),
        'session' => env('WAHA_SESSION', 'default'),
        'api_key' => env('WAHA_API_KEY'),
    ],

    'gtk' => [
        'base_url' => rtrim((string) env('GTK_API_BASE_URL', ''), '/'),
        'username' => env('GTK_API_USERNAME'),
        'password' => env('GTK_API_PASSWORD'),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'google/gemini-2.5-flash'),
        'base_url' => 'https://openrouter.ai/api/v1',
    ],

    'admin' => [
        'whatsapp_number' => env('ADMIN_WHATSAPP_NUMBER'),
    ],

];
