<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * S9 M11 the single attendance record of one attendee at one occurrence.
 *
 * @property Carbon|null $checked_in_at
 * @property Carbon|null $checked_out_at
 */
class EventAttendance extends Model
{
    public const CHECKED_IN = 'checked_in';

    public const CHECKED_OUT = 'checked_out';

    public const REVOKED = 'revoked';

    protected $fillable = ['calendar_event_id', 'event_booking_attendee_id', 'event_booking_id', 'status', 'checked_in_at', 'checked_out_at', 'source', 'checked_in_by'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime', 'checked_out_at' => 'datetime'];
    }

    public function isPresent(): bool
    {
        return in_array($this->status, [self::CHECKED_IN, self::CHECKED_OUT], true);
    }

    /**
     * @return BelongsTo<EventBookingAttendee, $this>
     */
    public function attendee(): BelongsTo
    {
        return $this->belongsTo(EventBookingAttendee::class, 'event_booking_attendee_id');
    }

    /**
     * Canonical booking relation; usable after event_booking_id migration.
     *
     * @return BelongsTo<EventBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(EventBooking::class, 'event_booking_id');
    }

    /**
     * @return BelongsTo<CalendarEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }
}
