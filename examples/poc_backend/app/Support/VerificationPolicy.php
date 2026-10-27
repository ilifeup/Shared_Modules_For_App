<?php

namespace App\Support;

use App\Models\User;

/**
 * Contact verification policy chosen by the administrator:
 * - email_required: email verification may grant membership.
 * - phone_required: phone OTP verification may grant membership.
 *
 * The selected contact workflow is not a membership status. Only the
 * persisted users.verified_status determines verified membership.
 * Changing the workflow never revokes an existing verified member.
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
