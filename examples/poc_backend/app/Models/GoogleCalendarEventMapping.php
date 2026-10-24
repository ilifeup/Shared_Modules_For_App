<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S9 M09 one Google event per member connection and source (booking occurrence or
 * private entry): the idempotency anchor of the one-way sync.
 */
class GoogleCalendarEventMapping extends Model
{
    public const SOURCE_BOOKING = 'booking';

    public const SOURCE_PRIVATE = 'private';

    protected $fillable = ['google_calendar_connection_id', 'source_type', 'source_id', 'google_event_id', 'status'];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime', 'attempts' => 'integer'];
    }

    /**
     * @return BelongsTo<GoogleCalendarConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleCalendarConnection::class, 'google_calendar_connection_id');
    }
}
