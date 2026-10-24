<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // SMS OTP providers. Credentials live only in the server environment; the provider
    // in use and test mode are selected in Filament (系統設定 › SMS OTP).
    'sms' => [
        'mitake' => [
            'username' => env('MITAKE_USERNAME'),
            'password' => env('MITAKE_PASSWORD'),
            'endpoint' => env('MITAKE_ENDPOINT', 'https://smsapi.mitake.com.tw/api/mtk/SmSend'),
        ],
        'every8d' => [
            'uid' => env('EVERY8D_UID'),
            'password' => env('EVERY8D_PASSWORD'),
            'site' => env('EVERY8D_SITE', 'new.e8d.tw'),
        ],
        'smsgo' => [
            'username' => env('SMSGO_USERNAME'),
            'password' => env('SMSGO_PASSWORD'),
            'endpoint' => env('SMSGO_ENDPOINT', 'https://www.smsgo.com.tw/sms_gw/sendsms.aspx'),
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
