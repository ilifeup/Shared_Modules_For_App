<?php

declare(strict_types=1);

// Standalone integration check: run with a dedicated disposable MySQL database.
// This deliberately bypasses phpunit.xml, which forces SQLite + sync queue.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\DispatchBookingGoogleSync;
use Illuminate\Support\Facades\DB;

if (DB::getDriverName() !== 'mysql'
    || config('queue.default') !== 'database'
    || config('queue.connections.database.after_commit') !== false
    || (config('queue.connections.database.connection') !== null
        && config('queue.connections.database.connection') !== DB::getDefaultConnection())) {
    throw new RuntimeException('Expected MySQL with same-DB database queue and after_commit=false');
}

$queue = DB::table('jobs');
$marker = 'mysql-atomicity-'.bin2hex(random_bytes(8));
$find = static fn (): int => DB::table('jobs')->where('queue', $marker)->count();

try {
    DB::beginTransaction();
    DispatchBookingGoogleSync::dispatch(1, 1)->onConnection('database')->onQueue($marker);
    if ($find() !== 1) {
        throw new RuntimeException('Job not inserted inside transaction');
    }
    DB::rollBack();
    if ($find() !== 0) {
        throw new RuntimeException('Rolled-back job survived');
    }

    DB::beginTransaction();
    DispatchBookingGoogleSync::dispatch(1, 1)->onConnection('database')->onQueue($marker);
    DB::commit();
    if ($find() !== 1) {
        throw new RuntimeException('Committed job missing');
    }
    echo "PASS MySQL database queue atomic commit/rollback\n";
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::table('jobs')->where('queue', $marker)->delete();
}
