<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S9 M03 one reviewer's vote (unique per request and reviewer).
 */
class VerificationReview extends Model
{
    public const UPDATED_AT = null;

    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    protected $fillable = ['verification_request_id', 'reviewer_id', 'decision', 'comment'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
