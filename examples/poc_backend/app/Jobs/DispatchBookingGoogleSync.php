<?php

namespace App\Jobs;

use App\Services\Calendar\GoogleCalendarSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Durable handoff for a booking Google sync request. Enqueue on the booking
 * database connection inside the same transaction; execute after commit.
 * The existing SyncGoogleCalendarItem remains responsible for Google retries.
 */
class DispatchBookingGoogleSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $userId,
        public readonly int $eventId,
    ) {}

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(GoogleCalendarSync $sync): void
    {
        $sync->queueBooking($this->userId, $this->eventId);
    }
}
