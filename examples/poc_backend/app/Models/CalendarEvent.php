<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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

    protected $fillable = ['title', 'description', 'location', 'starts_at', 'ends_at', 'audience', 'status'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
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
