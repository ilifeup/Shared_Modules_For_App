<?php

use App\Services\Analytics\LinkAnalytics;
use App\Services\Checkin\CheckinService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// S9 retention jobs (run by the scheduler: `php artisan schedule:run` every minute).
Artisan::command('s9:purge-checkins', function (CheckinService $checkins) {
    $result = $checkins->purge();
    $this->info('M11 check-in purge: '.json_encode($result));
})->purpose('Delete check-in records past the App retention (M11-D24/D28)');

Artisan::command('s9:purge-link-stats', function (LinkAnalytics $analytics) {
    $result = $analytics->purge();
    $this->info('M17 cleanup: '.json_encode($result));
})->purpose('Delete raw clicks after 90 days and aggregates after 24 months (M17-D22)');

Schedule::command('s9:purge-checkins')->dailyAt('03:10');
Schedule::command('s9:purge-link-stats')->dailyAt('03:20');
