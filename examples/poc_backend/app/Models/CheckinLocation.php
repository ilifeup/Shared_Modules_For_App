<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * S9 M11 fixed Location QR (D09, D19, D22): one place, many occurrences.
 */
class CheckinLocation extends Model
{
    protected $fillable = ['name', 'latitude', 'longitude', 'is_active'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (CheckinLocation $location): void {
            $location->token ??= Str::random(32);
        });
    }

    /**
     * @return BelongsToMany<CalendarEvent, $this>
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(CalendarEvent::class, 'checkin_location_events');
    }
}
