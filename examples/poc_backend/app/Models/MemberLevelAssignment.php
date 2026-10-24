<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One period at a level. Expired or revoked rows are kept as history.
 *
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $revoked_at
 */
class MemberLevelAssignment extends Model
{
    protected $fillable = ['user_id', 'membership_level_id', 'starts_at', 'ends_at', 'reason', 'assigned_by'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<MemberLevelAssignment>  $query
     */
    public function scopeActiveAt(Builder $query, Carbon $moment): void
    {
        $query->whereNull('revoked_at')
            ->where('starts_at', '<=', $moment)
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $moment));
    }

    public function isActiveAt(Carbon $moment): bool
    {
        return $this->revoked_at === null
            && $this->starts_at->lte($moment)
            && ($this->ends_at === null || $this->ends_at->gt($moment));
    }

    /**
     * Text label for admin lists (state is never shown by color only).
     */
    public function stateLabel(): string
    {
        $now = now();

        return match (true) {
            $this->revoked_at !== null => '已撤銷',
            $this->starts_at->gt($now) => '尚未開始',
            $this->ends_at !== null && $this->ends_at->lte($now) => '已到期',
            default => '有效',
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MembershipLevel, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(MembershipLevel::class, 'membership_level_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
