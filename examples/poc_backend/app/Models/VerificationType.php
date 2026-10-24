<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * S9 M03 verification type configured by each App (D01, D02, D04, D06, D09, D10).
 */
class VerificationType extends Model
{
    public const NOTIFY_NONE = 'none';

    public const NOTIFY_APP = 'app';

    public const NOTIFY_EMAIL = 'email';

    protected $fillable = ['name', 'description', 'requires_evidence', 'required_approvals', 'validity_days', 'allow_multiple', 'revoke_notify', 'is_active', 'sort'];

    protected function casts(): array
    {
        return [
            'requires_evidence' => 'boolean',
            'required_approvals' => 'integer',
            'validity_days' => 'integer',
            'allow_multiple' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }
}
