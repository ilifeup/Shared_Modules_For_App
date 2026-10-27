<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Member verification policy chosen by the admin (會員驗證設定):
 * - email_required: a verified email grants full member access (phone OTP optional)
 * - phone_required: a verified phone grants full member access (email can follow later)
 *
 * Contact checks grant membership; only persisted verified_status = 2 grants access.
 * Changing the contact workflow must never revoke an already verified member.
 */
final class VerificationPolicy
{
    public function __construct(private readonly Settings $settings) {}

    public function current(): string
    {
        return $this->settings->string('verification_policy');
    }

    /**
     * The contact that still has to be verified, or null when the member has full access.
     */
    public function missing(User $user): ?string
    {
        if ((int) $user->verified_status === 2) {
            return null;
        }

        return match ($this->current()) {
            Settings::POLICY_PHONE => 'phone',
            default => 'email',
        };
    }

    /** Grant verified membership once the configured contact check succeeds. */
    public function grantIfEligible(User $user): void
    {
        // Keep the membership qualification decision and update atomic even
        // when callers do not already have an open database transaction.
        DB::transaction(function () use ($user): void {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $contactVerified = match ($this->current()) {
                Settings::POLICY_PHONE => $fresh->hasVerifiedPhone(),
                default => $fresh->hasVerifiedEmail(),
            };
            if ((int) $fresh->verified_status === 1 && $contactVerified) {
                $fresh->forceFill(['verified_status' => 2])->save();
            }
        });
    }

    public function isSatisfiedBy(User $user): bool
    {
        return (int) $user->verified_status === 2;
    }

    /**
     * @return array{policy: string, satisfied: bool, missing: string|null}
     */
    public function describe(User $user): array
    {
        $missing = $this->missing($user);

        return [
            'policy' => $this->current(),
            'satisfied' => (int) $user->verified_status === 2,
            'missing' => $missing,
        ];
    }
}
