<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * S6 M08 booking of one member for one calendar event. Never deleted.
 *
 * @property Carbon $booked_at
 * @property Carbon|null $cancelled_at
 */
class EventBooking extends Model
{
    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_WAITLISTED = 'waitlisted';

    public const STATUS_EVENT_CANCELLED = 'event_cancelled';

    protected $fillable = ['calendar_event_id', 'user_id', 'status', 'active', 'booked_at'];

    protected function casts(): array
    {
        return [
            'booked_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    /**
     * Active reservations occupy a unique member/event slot, whether they are
     * confirmed or still waiting. Cancelled rows remain as immutable history.
     */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_CONFIRMED, self::STATUS_WAITLISTED], true)
            && (int) $this->active === 1;
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
