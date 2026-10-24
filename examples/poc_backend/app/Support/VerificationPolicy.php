<?php

namespace App\Support;

use App\Models\User;

/**
 * Member verification policy chosen by the admin (會員驗證設定):
 * - email_required: a verified email grants full member access (phone OTP optional)
 * - phone_required: a verified phone grants full member access (email can follow later)
 *
 * Enforced on every protected request, so already logged-in members cannot bypass a change.
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
        return match ($this->current()) {
            Settings::POLICY_PHONE => $user->hasVerifiedPhone() ? null : 'phone',
            default => $user->hasVerifiedEmail() ? null : 'email',
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
