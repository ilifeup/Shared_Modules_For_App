<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * S5 M09 calendar event. Stored in UTC; Apps display local time.
 *
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $cancelled_at
 */
class CalendarEvent extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    /** Kept and shown with its status; never deleted. */
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_CANCELLED];

    public const AUDIENCE_PUBLIC = 'public';

    public const AUDIENCE_MEMBERS = 'members';

    /** Longest date range one query may ask for, and the most events it returns. */
    public const MAX_RANGE_DAYS = 62;

    public const MAX_RESULTS = 200;

    protected $fillable = [
        'title', 'description', 'location', 'starts_at', 'ends_at', 'audience', 'status',
        'booking_enabled', 'booking_capacity', 'booking_opens_at', 'booking_closes_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'booking_enabled' => 'boolean',
            'booking_capacity' => 'integer',
            'booking_opens_at' => 'datetime',
            'booking_closes_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CalendarEvent $event): void {
            if ($event->starts_at === null || $event->ends_at === null || ! $event->ends_at->greaterThan($event->starts_at)) {
                throw ValidationException::withMessages([
                    'ends_at' => ['結束時間必須晚於開始時間。'],
                ]);
            }

            // S6: bookable events need a capacity; the booking window must make sense.
            if ($event->booking_enabled && ($event->booking_capacity === null || $event->booking_capacity < 1)) {
                throw ValidationException::withMessages([
                    'booking_capacity' => ['開放預約時，名額至少 1 人。'],
                ]);
            }

            if ($event->booking_opens_at !== null && $event->booking_closes_at !== null && ! $event->booking_closes_at->greaterThan($event->booking_opens_at)) {
                throw ValidationException::withMessages([
                    'booking_closes_at' => ['預約截止時間必須晚於開放時間。'],
                ]);
            }
        });

        static::saved(function (CalendarEvent $event): void {
            if (! $event->wasRecentlyCreated && ! $event->wasChanged('status')) {
                return;
            }

            match ($event->status) {
                self::STATUS_PUBLISHED => AuditEvent::record('event.published', auth()->id(), $event),
                self::STATUS_CANCELLED => AuditEvent::record('event.cancelled', auth()->id(), $event),
                default => null,
            };
        });
    }

    /**
     * Published or cancelled (drafts are never visible outside the admin).
     *
     * @param  Builder<CalendarEvent>  $query
     */
    public function scopeListed(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_PUBLISHED, self::STATUS_CANCELLED]);
    }

    /**
     * @param  Builder<CalendarEvent>  $query
     */
    public function scopeVisibleToPublic(Builder $query): void
    {
        $query->listed()->where('audience', self::AUDIENCE_PUBLIC);
    }

    /**
     * Events overlapping [$from, $to).
     *
     * @param  Builder<CalendarEvent>  $query
     */
    public function scopeOverlapping(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    /**
     * @return HasMany<EventBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(EventBooking::class);
    }

    /**
     * When bookings close: the configured time, otherwise the event start.
     */
    public function bookingClosesAt(): Carbon
    {
        return $this->booking_closes_at ?? $this->starts_at;
    }

    public function cancel(?string $reason): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return;
        }

        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null,
        ])->save();
    }
}
