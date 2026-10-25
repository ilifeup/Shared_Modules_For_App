<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * S9 M08 one real attendee = one seat (the seat ledger). M11 check-in is per
 * attendee and occurrence.
 */
class EventBookingAttendee extends Model
{
    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_WAITLISTED = 'waitlisted';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['event_booking_group_id', 'calendar_event_id', 'member_user_id', 'name', 'status', 'active'];

    /**
     * @return BelongsTo<EventBookingGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(EventBookingGroup::class, 'event_booking_group_id');
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
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_user_id');
    }

    /**
     * @return HasOne<EventAttendance, $this>
     */
    public function attendance(): HasOne
    {
        return $this->hasOne(EventAttendance::class);
    }
}
