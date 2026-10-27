<?php

namespace App\Support;

use App\Models\User;

/**
 * Member verification policy chosen by the admin (會員驗證設定):
 * - email_required: a verified email grants full member access (phone OTP optional)
 * - phone_required: a verified phone grants full member access (email can follow later)
 *
 * Contact policy only selects how an unverified member may qualify.\n * Once granted, membership status is persisted in users.membership_verified_at.
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
        if ($user->isVerifiedMember()) {
            return null;
        }

        return match ($this->current()) {
            Settings::POLICY_PHONE => 'phone',
            default => 'email',
        };
    }

    public function isSatisfiedBy(User $user): bool
    {
        return $this->missing($user) === null;
    }

    /**
     * @return array{policy: string, satisfied: bool, missing: string|null}
     */
    public function describe(User $user): array
    {
        $missing = $this->missing($user);

        return [
            'policy' => $this->current(),
            'satisfied' => $missing === null,
            'missing' => $missing,
        ];
    }
}
