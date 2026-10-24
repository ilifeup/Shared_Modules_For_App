<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * S9 M09/M08 event category: calendar colour (M09-D10) and who may book
 * (M08-D01 guests, D02 proxy bookings, D10 required M03 verification).
 */
class EventCategory extends Model
{
    protected $fillable = ['name', 'color', 'sort', 'allow_guest_booking', 'allow_proxy_booking', 'required_verification_type_id'];

    protected function casts(): array
    {
        return [
            'allow_guest_booking' => 'boolean',
            'allow_proxy_booking' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<VerificationType, $this>
     */
    public function requiredVerificationType(): BelongsTo
    {
        return $this->belongsTo(VerificationType::class, 'required_verification_type_id');
    }

    /**
     * @return HasMany<CalendarEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }
}
