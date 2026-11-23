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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // E-Klaim INA-CBG (hanya get_claim_data, lihat App\Services\Eklaim\EklaimClient). Key tidak pernah dicetak/di-log.
    'eklaim' => [
        'ws_url' => env('EKLAIM_WS_URL'),
        'encryption_key' => env('EKLAIM_ENCRYPTION_KEY'),
        'sync_enabled' => (bool) env('EKLAIM_SYNC_ENABLED', false),
        'timeout' => 10,
        'jeda_ms' => 300,
    ],

];
