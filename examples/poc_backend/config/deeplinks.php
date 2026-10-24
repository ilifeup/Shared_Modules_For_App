<?php

/*
 * S9 M14 deep links: each App has its own HTTPS domain (D06, D07). The App Links /
 * Universal Links association files are only served once the real package
 * signature / Team ID are configured (D17; real-device verification is pending).
 */
return [
    'base_url' => env('DEEPLINK_BASE_URL', env('ARTICLE_SHARE_BASE_URL', env('APP_URL', 'http://localhost'))),

    'android' => [
        'package' => env('ANDROID_APP_PACKAGE'),
        // Comma separated SHA-256 fingerprints of the release signing certificate(s).
        'sha256_fingerprints' => array_values(array_filter(array_map('trim', explode(',', (string) env('ANDROID_APP_SHA256', ''))))),
        'store_url' => env('ANDROID_STORE_URL'),
    ],

    'ios' => [
        // TEAMID.bundle.id
        'app_id' => env('IOS_APP_ID'),
        'store_url' => env('IOS_STORE_URL'),
    ],

    // Shown when content has no cover (M14-D28); must be a public image URL.
    'default_image' => env('DEEPLINK_DEFAULT_IMAGE'),
];
