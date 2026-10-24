<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * S9 M09 VIP private calendar entry (owner only). Optional weekly/monthly
 * recurrence is expanded when read (M09-D23).
 *
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $recurrence_until
 */
class PrivateCalendarEvent extends Model
{
    protected $fillable = ['user_id', 'title', 'notes', 'starts_at', 'ends_at', 'timezone', 'recurrence', 'recurrence_until'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'recurrence_until' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
