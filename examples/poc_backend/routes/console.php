<?php

use App\Services\Analytics\LinkAnalytics;
use App\Services\Checkin\CheckinService;
use App\Services\Payment\CashInvoiceService;
use App\Support\S9DataGuard;
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

Artisan::command('payments:mark-overdue', function (CashInvoiceService $cash) {
    $this->info('Newly overdue unpaid invoices: '.$cash->expireDue());
})->purpose('Mark unpaid invoices overdue without cancelling bookings or releasing seats');

// Read-only: what an S9 rollback would delete (run before deciding on a rollback).
Artisan::command('s9:data-report', function () {
    $report = S9DataGuard::report();
    if ($report === []) {
        $this->info('S9 rollback would delete no data that the migration cannot rebuild.');

        return;
    }
    $this->warn('An S9 rollback would permanently delete:');
    $this->table(['data', 'rows'], array_map(fn ($k, $v) => [$k, $v], array_keys($report), $report));
})->purpose('Show the S9 rows a migration rollback would delete');

Schedule::command('s9:purge-checkins')->dailyAt('03:10');
Schedule::command('s9:purge-link-stats')->dailyAt('03:20');

Schedule::command('payments:mark-overdue')->hourly()->withoutOverlapping();
