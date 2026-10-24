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

    // Downstream PharmAssist services (base URLs come from the environment).
    'identity' => [
        'url' => env('IDENTITY_URL'),
    ],

    'inventory' => [
        'url' => env('INVENTORY_URL'),
    ],

    'integration' => [
        'url' => env('INTEGRATION_URL'),
        // Seconds the gateway waits for an OCR answer (every other route waits 3 s). Keep it above OCR_TIMEOUT_SECONDS.
        'ocr_timeout' => (float) env('GATEWAY_OCR_TIMEOUT_SECONDS', 20),
        // Seconds the gateway waits for each step of the inventory feed sync (translate, then apply).
        'sync_timeout' => (float) env('GATEWAY_SYNC_TIMEOUT_SECONDS', 10),
    ],

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

];
