<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FIFO position of one canonical booking, not a separate booking ledger.
 */
class EventWaitlistEntry extends Model
{
    public const WAITING = 'waiting';
    public const PROMOTED = 'promoted';
    public const CANCELLED = 'cancelled';
    public const SKIPPED = 'skipped';

    protected $fillable = ['calendar_event_id', 'event_booking_id', 'sequence', 'status'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<EventBooking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(EventBooking::class, 'event_booking_id');
    }
}
