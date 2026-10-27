<?php

use App\Models\User;
use App\Support\Settings;
use App\Support\VerificationPolicy;

it('preserves existing email verified members when admin switches to phone verification', function (): void {
    $settings = app(Settings::class);
    $settings->put(['verification_policy' => Settings::POLICY_EMAIL]);
    $existing = User::factory()->create(['email_verified_at' => now(), 'phone_verified_at' => null]);
    $unverified = User::factory()->create(['email_verified_at' => null, 'phone_verified_at' => null]);

    $settings->put(['verification_policy' => Settings::POLICY_PHONE]);

    expect(app(VerificationPolicy::class)->isSatisfiedBy($existing->fresh()))->toBeTrue()
        ->and($existing->fresh()->verification_grandfathered_at)->not->toBeNull()
        ->and(app(VerificationPolicy::class)->isSatisfiedBy($unverified->fresh()))->toBeFalse();

    $newMember = User::factory()->create(['email_verified_at' => now(), 'phone_verified_at' => null]);
    expect(app(VerificationPolicy::class)->missing($newMember))->toBe('phone');
});

it('keeps existing verification eligibility across subsequent policy changes', function (): void {
    $settings = app(Settings::class);
    $settings->put(['verification_policy' => Settings::POLICY_EMAIL]);
    $member = User::factory()->create(['email_verified_at' => now(), 'phone_verified_at' => null]);
    $settings->put(['verification_policy' => Settings::POLICY_PHONE]);
    $settings->put(['verification_policy' => Settings::POLICY_EMAIL]);
    $settings->put(['verification_policy' => Settings::POLICY_PHONE]);

    expect(app(VerificationPolicy::class)->isSatisfiedBy($member->fresh()))->toBeTrue();
});
