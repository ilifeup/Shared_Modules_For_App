<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S9 M03 private evidence file (local disk): the owner and authorised reviewers only (D12).
 */
class VerificationEvidence extends Model
{
    protected $table = 'verification_evidence';

    protected $fillable = ['verification_request_id', 'path', 'original_name', 'mime', 'size'];

    /**
     * @return BelongsTo<VerificationRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(VerificationRequest::class, 'verification_request_id');
    }
}
