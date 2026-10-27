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

it('does not grandfather email-only members created after switching to phone policy', function (): void {
    $settings = app(Settings::class);
    $settings->put(['verification_policy' => Settings::POLICY_PHONE]);

    $newMember = User::factory()->create(['email_verified_at' => now(), 'phone_verified_at' => null]);

    expect($newMember->fresh()->verification_grandfathered_at)->toBeNull()
        ->and(app(VerificationPolicy::class)->missing($newMember))->toBe('phone');
});

it('allows an existing grandfathered member without marking the phone as verified', function (): void {
    $settings = app(Settings::class);
    $settings->put(['verification_policy' => Settings::POLICY_EMAIL]);
    $existing = User::factory()->create(['email_verified_at' => now(), 'phone_verified_at' => null]);

    $settings->put(['verification_policy' => Settings::POLICY_PHONE]);

    expect($existing->fresh()->phone_verified_at)->toBeNull()
        ->and($existing->fresh()->verification_grandfathered_at)->not->toBeNull()
        ->and(app(VerificationPolicy::class)->isSatisfiedBy($existing->fresh()))->toBeTrue();
});

it('does not convert grandfathered verification into phone verification or a guest login', function (): void {
    $settings = app(Settings::class);
    $settings->put(['verification_policy' => Settings::POLICY_EMAIL]);
    $member = User::factory()->create(['email_verified_at' => now(), 'phone_verified_at' => null]);
    $settings->put(['verification_policy' => Settings::POLICY_PHONE]);

    $member->refresh();
    expect($member->verification_grandfathered_at)->not->toBeNull()
        ->and($member->phone_verified_at)->toBeNull()
        ->and(app(VerificationPolicy::class)->isSatisfiedBy($member))->toBeTrue();

    // Persisted qualification is independent of request authentication.
    $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
    expect($member->fresh()->verification_grandfathered_at)->not->toBeNull();
});

it('does not grandfather members when an invalid verification policy update is rejected', function (): void {
    $settings = app(Settings::class);
    $settings->put(['verification_policy' => Settings::POLICY_EMAIL]);
    $member = User::factory()->create(['email_verified_at' => now(), 'phone_verified_at' => null]);

    expect(fn () => $settings->put(['verification_policy' => 'invalid_policy']))
        ->toThrow(InvalidArgumentException::class);

    expect($member->fresh()->verification_grandfathered_at)->toBeNull()
        ->and($settings->string('verification_policy'))->toBe(Settings::POLICY_EMAIL);
});

it('does not grandfather an unverified account during the email-to-phone switch', function (): void {
    $settings = app(Settings::class);
    $settings->put(['verification_policy' => Settings::POLICY_EMAIL]);
    $unverified = User::factory()->create(['email_verified_at' => null, 'phone_verified_at' => null]);
    $settings->put(['verification_policy' => Settings::POLICY_PHONE]);

    expect($unverified->fresh()->verification_grandfathered_at)->toBeNull()
        ->and(app(VerificationPolicy::class)->missing($unverified->fresh()))->toBe('phone');
});

it('does not grandfather an email verification completed after the phone-policy switch', function (): void {
    $settings = app(Settings::class);
    $settings->put(['verification_policy' => Settings::POLICY_EMAIL]);
    $member = User::factory()->create(['email_verified_at' => null, 'phone_verified_at' => null]);
    $settings->put(['verification_policy' => Settings::POLICY_PHONE]);

    $member->forceFill(['email_verified_at' => now()])->save();

    expect($member->fresh()->verification_grandfathered_at)->toBeNull()
        ->and(app(VerificationPolicy::class)->missing($member->fresh()))->toBe('phone');
});

it('requires phone verification after an administrator revokes grandfathered eligibility', function (): void {
    $settings = app(Settings::class);
    $settings->put(['verification_policy' => Settings::POLICY_EMAIL]);
    $member = User::factory()->create([
        'email_verified_at' => now(),
        'phone_verified_at' => null,
    ]);

    $settings->put(['verification_policy' => Settings::POLICY_PHONE]);
    expect(app(VerificationPolicy::class)->isSatisfiedBy($member->fresh()))->toBeTrue();

    // Verify the persisted qualification check only. This does not establish
    // that an administrator revocation UI or audit trail exists.
    $member->forceFill(['verification_grandfathered_at' => null])->save();

    expect(app(VerificationPolicy::class)->missing($member->fresh()))->toBe('phone');
});
