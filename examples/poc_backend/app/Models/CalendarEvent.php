<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    // Keep newly created Eloquent instances aligned with the MySQL column default.
    protected $attributes = ['waitlist_capacity' => 5];

    protected $fillable = [
        'title', 'description', 'location', 'starts_at', 'ends_at', 'audience', 'status',
        'booking_enabled', 'booking_capacity', 'waitlist_capacity', 'booking_opens_at', 'booking_closes_at',
        // S9 (M08/M09/M11)
        'event_category_id', 'timezone', 'floor', 'studio', 'booking_max_seats', 'waitlist_enabled',
        'booking_close_offset_minutes', 'cancel_closes_at', 'cancel_offset_minutes',
        'checkin_enabled', 'checkout_enabled', 'checkin_open_before_minutes', 'checkin_close_after_end_minutes',
        'checkin_gps_required', 'checkin_gps_radius_m',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'booking_enabled' => 'boolean',
            'booking_capacity' => 'integer',
            'waitlist_capacity' => 'integer',
            'booking_opens_at' => 'datetime',
            'booking_closes_at' => 'datetime',
            'series_original_start_at' => 'datetime',
            'is_series_exception' => 'boolean',
            'booking_max_seats' => 'integer',
            'waitlist_enabled' => 'boolean',
            'booking_close_offset_minutes' => 'integer',
            'cancel_closes_at' => 'datetime',
            'cancel_offset_minutes' => 'integer',
            'checkin_enabled' => 'boolean',
            'checkout_enabled' => 'boolean',
            'checkin_open_before_minutes' => 'integer',
            'checkin_close_after_end_minutes' => 'integer',
            'checkin_gps_required' => 'boolean',
            'checkin_gps_radius_m' => 'integer',
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

            if ($event->waitlist_capacity !== null && $event->waitlist_capacity < 0) {
                throw ValidationException::withMessages([
                    'waitlist_capacity' => ['候補名額不得小於 0。'],
                ]);
            }

            // The legacy per-booking seat setting remains readable for old event rows.
            // Single-member requests are enforced by the booking API, not event model saves.

            if ($event->timezone !== null && ! in_array($event->timezone, timezone_identifiers_list(), true)) {
                throw ValidationException::withMessages(['timezone' => ['時區格式錯誤。']]);
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
     * When bookings close (S9-L02 A): a relative offset is recomputed from the
     * current start; otherwise the fixed time; otherwise the event start.
     */
    public function bookingClosesAt(): Carbon
    {
        if ($this->booking_close_offset_minutes !== null) {
            return $this->starts_at->copy()->subMinutes($this->booking_close_offset_minutes);
        }

        return $this->booking_closes_at ?? $this->starts_at;
    }

    /**
     * Last moment a member may cancel (M08-D04, same rules as bookingClosesAt()).
     */
    public function cancelClosesAt(): Carbon
    {
        if ($this->cancel_offset_minutes !== null) {
            return $this->starts_at->copy()->subMinutes($this->cancel_offset_minutes);
        }

        return $this->cancel_closes_at ?? $this->starts_at;
    }

    /**
     * Fixed (admin-set) deadlines are kept on reschedule; the admin is warned.
     *
     * @return list<string>
     */
    public function fixedDeadlines(): array
    {
        return array_values(array_filter([
            $this->booking_close_offset_minutes === null && $this->booking_closes_at !== null ? 'booking_closes_at' : null,
            $this->cancel_offset_minutes === null && $this->cancel_closes_at !== null ? 'cancel_closes_at' : null,
            $this->booking_opens_at !== null ? 'booking_opens_at' : null,
        ]));
    }

    public function checkinOpensAt(): Carbon
    {
        return $this->starts_at->copy()->subMinutes($this->checkin_open_before_minutes ?? 60);
    }

    public function checkinClosesAt(): Carbon
    {
        return $this->ends_at->copy()->addMinutes($this->checkin_close_after_end_minutes ?? 0);
    }

    /**
     * @return BelongsTo<EventCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'event_category_id');
    }

    /**
     * @return BelongsTo<EventSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(EventSeries::class, 'event_series_id');
    }

    /**
     * @return HasMany<EventBookingGroup, $this>
     */
    public function bookingGroups(): HasMany
    {
        return $this->hasMany(EventBookingGroup::class);
    }

    /**
     * @return HasMany<EventBookingAttendee, $this>
     */
    public function attendees(): HasMany
    {
        return $this->hasMany(EventBookingAttendee::class);
    }

    /**
     * @return BelongsToMany<CheckinLocation, $this>
     */
    public function checkinLocations(): BelongsToMany
    {
        return $this->belongsToMany(CheckinLocation::class, 'checkin_location_events');
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
