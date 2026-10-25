<?php

namespace App\Services\Booking;

use App\Models\User;

/**
 * S9 M08 a validated booking request for BookingEngine::book().
 */
final class BookingRequest
{
    /**
     * @param  list<array{name: string, member_user_id?: int|null}>  $attendees
     */
    public function __construct(
        public readonly ?User $booker,
        public readonly string $contactName,
        public readonly ?string $contactEmail,
        public readonly ?string $contactPhone,
        public readonly array $attendees,
        public readonly ?string $clientRequestId = null,
        public readonly bool $byAdmin = false,
        public readonly ?User $actor = null,
    ) {}
}
