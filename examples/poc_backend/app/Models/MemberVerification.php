<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * S9 M03 granted verification (badge). Valid while not revoked and not expired;
 * expiry needs no job (D11: no reminder, ends automatically).
 *
 * @property Carbon $granted_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 */
class MemberVerification extends Model
{
    protected $fillable = ['user_id', 'verification_type_id', 'verification_request_id', 'granted_at', 'expires_at', 'is_public'];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'is_public' => 'boolean',
        ];
    }

    /**
     * @param  Builder<MemberVerification>  $query
     */
    public function scopeValid(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isValid(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function state(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at !== null && ! $this->expires_at->isFuture() => 'expired',
            default => 'valid',
        };
    }

    /**
     * @return BelongsTo<VerificationType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(VerificationType::class, 'verification_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
