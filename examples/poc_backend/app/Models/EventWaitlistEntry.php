<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S9 M08 waitlist position of one whole group (strict FIFO by sequence).
 */
class EventWaitlistEntry extends Model
{
    public const WAITING = 'waiting';

    public const PROMOTED = 'promoted';

    public const CANCELLED = 'cancelled';

    public const SKIPPED = 'skipped';

    protected $fillable = ['calendar_event_id', 'event_booking_group_id', 'sequence', 'status'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'resolved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<EventBookingGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(EventBookingGroup::class, 'event_booking_group_id');
    }
}
