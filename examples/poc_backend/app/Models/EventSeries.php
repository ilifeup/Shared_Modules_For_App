<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * S9 M09 weekly/monthly series. Every generated occurrence is a calendar_events
 * row (S9-L05 A); the series only holds the rule.
 *
 * @property Carbon $starts_on
 * @property Carbon $until_on
 */
class EventSeries extends Model
{
    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    /** Upper bound of occurrences one series may generate. */
    public const MAX_OCCURRENCES = 200;

    protected $table = 'event_series';

    protected $fillable = ['title', 'description', 'frequency', 'timezone', 'local_start_time', 'duration_minutes', 'starts_on', 'until_on', 'created_by'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'until_on' => 'date',
            'duration_minutes' => 'integer',
        ];
    }

    /**
     * @return HasMany<CalendarEvent, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(CalendarEvent::class, 'event_series_id')->orderBy('series_original_start_at');
    }
}
