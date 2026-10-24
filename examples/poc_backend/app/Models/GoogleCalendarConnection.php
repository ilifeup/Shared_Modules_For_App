<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * S9 M09 one Google account per member (D16). Tokens are encrypted at rest and
 * never returned by the API.
 *
 * @property Carbon|null $access_token_expires_at
 * @property Carbon|null $sync_from
 */
class GoogleCalendarConnection extends Model
{
    public const ACTIVE = 'active';

    public const DISCONNECTED = 'disconnected';

    public const ERROR = 'error';

    protected $fillable = ['user_id', 'google_calendar_id', 'refresh_token', 'access_token', 'access_token_expires_at', 'status', 'connected_at', 'sync_from'];

    protected $hidden = ['refresh_token', 'access_token'];

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'sync_from' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE && $this->google_calendar_id !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<GoogleCalendarEventMapping, $this>
     */
    public function mappings(): HasMany
    {
        return $this->hasMany(GoogleCalendarEventMapping::class);
    }
}
