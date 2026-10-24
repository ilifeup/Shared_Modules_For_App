<?php

namespace App\Jobs;

use App\Models\GoogleCalendarConnection;
use App\Services\Calendar\GoogleCalendarException;
use App\Services\Calendar\GoogleCalendarSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * S9 M09 one Google item (booking occurrence or private entry). Limited automatic
 * retries; afterwards the item is "failed" and admins can retry it (S9-L03 A).
 */
class SyncGoogleCalendarItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $connectionId,
        public readonly string $sourceType,
        public readonly int $sourceId,
    ) {}

    /**
     * @return list<int> seconds between attempts
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(GoogleCalendarSync $sync): void
    {
        $connection = GoogleCalendarConnection::query()->find($this->connectionId);
        if ($connection === null) {
            return;
        }

        $sync->noteAttempt($this->connectionId, $this->sourceType, $this->sourceId);

        try {
            $sync->sync($connection, $this->sourceType, $this->sourceId);
        } catch (GoogleCalendarException $e) {
            if ($e->isAuthFailure()) {
                // Revoked consent: retrying cannot help; the member must reconnect.
                $sync->markFailed($this->connectionId, $this->sourceType, $this->sourceId, $e->getMessage(), true);
                $this->fail($e);

                return;
            }
            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        app(GoogleCalendarSync::class)->markFailed($this->connectionId, $this->sourceType, $this->sourceId, $e?->getMessage() ?? 'failed');
    }
}
