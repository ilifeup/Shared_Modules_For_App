<?php

use App\Models\User;
use App\Support\Settings;
use App\Support\VerificationPolicy;
use Illuminate\Support\Facades\URL;

it('uses only persisted membership status to authorize a registered user', function (): void {
    $settings = app(Settings::class);
    $settings->put(['verification_policy' => Settings::POLICY_EMAIL]);
    $verified = User::factory()->create([
        'verified_status' => 2,
        'email_verified_at' => null,
        'phone_verified_at' => null,
    ]);
    $pending = User::factory()->create([
        'verified_status' => 1,
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    expect(app(VerificationPolicy::class)->isSatisfiedBy($verified->fresh()))->toBeTrue()
        ->and(app(VerificationPolicy::class)->isSatisfiedBy($pending->fresh()))->toBeFalse();
});

it('never changes stored membership when contact verification policy changes', function (): void {
    $settings = app(Settings::class);
    $settings->put(['verification_policy' => Settings::POLICY_EMAIL]);
    $verified = User::factory()->create(['verified_status' => 2, 'phone_verified_at' => null]);
    $pending = User::factory()->create(['verified_status' => 1, 'email_verified_at' => now()]);

    $settings->put(['verification_policy' => Settings::POLICY_PHONE]);
    expect($verified->fresh()->verified_status)->toBe(2)
        ->and($pending->fresh()->verified_status)->toBe(1)
        ->and(app(VerificationPolicy::class)->isSatisfiedBy($verified->fresh()))->toBeTrue()
        ->and(app(VerificationPolicy::class)->missing($pending->fresh()))->toBe('phone');

    $settings->put(['verification_policy' => Settings::POLICY_EMAIL]);
    expect($verified->fresh()->verified_status)->toBe(2)
        ->and($pending->fresh()->verified_status)->toBe(1);
});

it('does not grant membership from a verification timestamp alone', function (): void {
    $settings = app(Settings::class);
    $settings->put(['verification_policy' => Settings::POLICY_PHONE]);
    $pending = User::factory()->create([
        'verified_status' => 1,
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    expect(app(VerificationPolicy::class)->isSatisfiedBy($pending->fresh()))->toBeFalse()
        ->and(app(VerificationPolicy::class)->missing($pending->fresh()))->toBe('phone');
});

it('keeps contact timestamps from overriding the User model membership authority', function (): void {
    $pending = User::factory()->create([
        'verified_status' => 1,
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    $verified = User::factory()->create([
        'verified_status' => 2,
        'email_verified_at' => null,
        'phone_verified_at' => null,
    ]);

    expect($pending->fresh()->isVerifiedMember())->toBeFalse()
        ->and($verified->fresh()->isVerifiedMember())->toBeTrue();

    $verified->forceFill(['verified_status' => 1])->save();
    expect($verified->fresh()->isVerifiedMember())->toBeFalse();
});

it('keeps guest access separate from persisted registered membership', function (): void {
    $member = User::factory()->create(['verified_status' => 2]);
    $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
    expect($member->fresh()->verified_status)->toBe(2);
});

it('rejects an invalid policy without changing stored membership', function (): void {
    $settings = app(Settings::class);
    $member = User::factory()->create(['verified_status' => 2]);
    expect(fn () => $settings->put(['verification_policy' => 'invalid_policy']))
        ->toThrow(InvalidArgumentException::class);
    expect($member->fresh()->verified_status)->toBe(2);
});

it('requires verification when membership is explicitly revoked in the DB', function (): void {
    $member = User::factory()->create(['verified_status' => 2]);
    expect(app(VerificationPolicy::class)->isSatisfiedBy($member->fresh()))->toBeTrue();
    $member->forceFill(['verified_status' => 1])->save();
    expect(app(VerificationPolicy::class)->isSatisfiedBy($member->fresh()))->toBeFalse();
});

it('atomically grants stored membership on valid email verification', function (): void {
    app(Settings::class)->put(['verification_policy' => Settings::POLICY_EMAIL]);
    $member = User::factory()->unverified()->create(['email' => 'verify-member@example.test']);
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
        'id' => $member->id,
        'hash' => sha1($member->getEmailForVerification()),
    ]);

    $this->get($url)->assertOk();
    expect($member->fresh()->email_verified_at)->not->toBeNull()
        ->and($member->fresh()->verified_status)->toBe(2);
});

it('rejects an old signed email link after the email changes without granting membership', function (): void {
    app(Settings::class)->put(['verification_policy' => Settings::POLICY_EMAIL]);
    $member = User::factory()->unverified()->create(['email' => 'old-member@example.test']);
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
        'id' => $member->id,
        'hash' => sha1($member->getEmailForVerification()),
    ]);
    $member->forceFill(['email' => 'new-member@example.test'])->save();

    $this->get($url)->assertForbidden();
    expect($member->fresh()->email_verified_at)->toBeNull()
        ->and($member->fresh()->verified_status)->toBe(1);
});
