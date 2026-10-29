<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * S9 M08 one booking for exactly one registered member. The legacy group table
 * name remains until the coordinated schema migration is verified.
 *
 * @property Carbon $booked_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $cancelled_at
 */
class EventBookingGroup extends Model
{
    use Notifiable;

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_WAITLISTED = 'waitlisted';

    public const STATUS_CANCELLED = 'cancelled';

    /** The whole occurrence was cancelled (no promotion). */
    public const STATUS_EVENT_CANCELLED = 'event_cancelled';

    protected $fillable = [
        'calendar_event_id', 'legacy_booking_id', 'booker_user_id', 'contact_name', 'contact_email',
        'contact_phone', 'status', 'seat_count', 'client_request_id', 'booked_at', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'seat_count' => 'integer',
            'booked_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_CONFIRMED, self::STATUS_WAITLISTED], true);
    }

    /**
     * @return BelongsTo<CalendarEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function booker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booker_user_id');
    }

    /**
     * @return BelongsTo<EventBooking, $this>
     */
    public function legacyBooking(): BelongsTo
    {
        return $this->belongsTo(EventBooking::class, 'legacy_booking_id');
    }

    /**
     * Single-member booking contract: one participant per booking.
     *
     * @return HasOne<EventBookingAttendee, $this>
     */
    public function attendee(): HasOne
    {
        return $this->hasOne(EventBookingAttendee::class, 'event_booking_group_id');
    }

    /**
     * Legacy relation retained temporarily for existing callers and data audits.
     * Remove only after the one-to-one migration and API refactor are verified.
     *
     * @return HasMany<EventBookingAttendee, $this>
     */
    public function attendees(): HasMany
    {
        return $this->hasMany(EventBookingAttendee::class)->orderBy('id');
    }

    /**
     * @return HasOne<EventWaitlistEntry, $this>
     */
    public function waitlistEntry(): HasOne
    {
        return $this->hasOne(EventWaitlistEntry::class);
    }
}
