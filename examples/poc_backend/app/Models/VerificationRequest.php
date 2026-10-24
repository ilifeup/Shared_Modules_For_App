<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * S9 M03 a member's application. Rejected applications stay; the member may apply again (D05).
 *
 * @property Carbon|null $decided_at
 */
class VerificationRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    protected $fillable = ['user_id', 'verification_type_id', 'status', 'note'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<VerificationType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(VerificationType::class, 'verification_type_id');
    }

    /**
     * @return HasMany<VerificationEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(VerificationEvidence::class);
    }

    /**
     * @return HasMany<VerificationReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(VerificationReview::class);
    }
}
