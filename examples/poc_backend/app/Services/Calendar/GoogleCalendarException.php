<?php

namespace App\Services\Calendar;

use RuntimeException;

/**
 * Google API failure with its HTTP status only (0 = connection failure).
 */
class GoogleCalendarException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    /** Revoked consent / invalid grant: retrying will not help. */
    public function isAuthFailure(): bool
    {
        return in_array($this->status, [400, 401, 403], true);
    }
}
