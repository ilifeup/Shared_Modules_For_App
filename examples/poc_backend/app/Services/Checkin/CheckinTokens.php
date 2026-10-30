<?php

namespace App\Services\Checkin;

/**
 * S9 M11 rotating QR tokens (D10, D21, D25): HMAC-signed, no personal data, no
 * database writes. A token belongs to one 60-second window; the previous window is
 * still accepted for 10 seconds after it ended. The server clock decides.
 *
 *   e.{eventId}.{window}.{sig}     event QR shown at the venue (members scan it)
 *   a.{attendeeId}.{window}.{sig}  legacy attendee pass (temporary compatibility)
 *   b.{bookingId}.{window}.{sig}   canonical booking pass
 */
class CheckinTokens
{
    public const WINDOW_SECONDS = 60;

    public const GRACE_SECONDS = 10;

    public function window(?int $time = null): int
    {
        return intdiv($time ?? now()->getTimestamp(), self::WINDOW_SECONDS);
    }

    public function event(int $eventId, ?int $time = null): string
    {
        return $this->make('e', $eventId, $this->window($time));
    }

    public function attendee(int $attendeeId, ?int $time = null): string
    {
        return $this->make('a', $attendeeId, $this->window($time));
    }

    /** Canonical booking QR: separate kind prevents collisions with legacy attendee IDs. */
    public function booking(int $bookingId, ?int $time = null): string
    {
        return $this->make('b', $bookingId, $this->window($time));
    }

    /** Seconds until the current token rotates. */
    public function expiresIn(?int $time = null): int
    {
        $time ??= now()->getTimestamp();

        return self::WINDOW_SECONDS - ($time % self::WINDOW_SECONDS);
    }

    /**
     * The subject id if $token is a valid, current (or within grace) token of $kind.
     */
    public function verify(string $token, string $kind, ?int $time = null): ?int
    {
        if (preg_match('/^([eab])\.(\d{1,19})\.(\d{1,12})\.([0-9a-f]{24})$/', $token, $m) !== 1 || $m[1] !== $kind) {
            return null;
        }
        [$id, $window] = [(int) $m[2], (int) $m[3]];
        if (! hash_equals($this->sign($kind, $id, $window), $m[4])) {
            return null;
        }

        $time ??= now()->getTimestamp();
        $current = $this->window($time);
        $secondsIntoWindow = $time - $current * self::WINDOW_SECONDS;
        $valid = $window === $current || ($window === $current - 1 && $secondsIntoWindow < self::GRACE_SECONDS);

        return $valid ? $id : null;
    }

    private function make(string $kind, int $id, int $window): string
    {
        return $kind.'.'.$id.'.'.$window.'.'.$this->sign($kind, $id, $window);
    }

    private function sign(string $kind, int $id, int $window): string
    {
        return substr(hash_hmac('sha256', 'checkin|'.$kind.'|'.$id.'|'.$window, (string) config('app.key')), 0, 24);
    }
}
