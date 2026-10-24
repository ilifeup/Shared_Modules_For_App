<?php

/*
 * S9 M09 Google Calendar one-way sync. Credentials come only from the server
 * environment (never the App or Git). Without them the feature reports
 * GOOGLE_NOT_CONFIGURED (BLOCKED until real OAuth credentials exist).
 */
return [
    'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_CALENDAR_REDIRECT_URI'),
    // Only calendars this App created (least privilege, M09 D17).
    'scope' => 'https://www.googleapis.com/auth/calendar.app.created',
    'calendar_name' => env('GOOGLE_CALENDAR_NAME', env('APP_NAME', 'App').' 活動'),
    'timeout' => (int) env('GOOGLE_CALENDAR_TIMEOUT', 15),
];
