<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneOtp extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_USED = 'used';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_SUPERSEDED = 'superseded';

    /** Statuses that count toward hourly limits and cooldown. */
    public const COUNTED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENT,
        self::STATUS_USED,
        self::STATUS_EXPIRED,
        self::STATUS_LOCKED,
        self::STATUS_SUPERSEDED,
    ];

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'phone_e164',
        'code_hash',
        'status',
        'attempts',
        'provider',
        'provider_message_id',
        'expires_at',
        'used_at',
    ];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
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
