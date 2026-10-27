<?php

use App\Filament\Pages\VerificationPolicySettings;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Settings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

function memberToken(User $user): string
{
    return $user->createToken('mobile')->plainTextToken;
}

it('starts with the email_required policy and exposes it publicly', function () {
    $this->getJson('/api/v1/auth/policy')
        ->assertOk()
        ->assertJsonPath('data.verification_policy', 'email_required')
        ->assertJsonPath('data.required_fields', ['name', 'email', 'phone', 'password']);
});

it('lets an unverified member log in but only reach verification endpoints', function () {
    $user = User::factory()->unverified()->create(['email' => 'new@example.com', 'phone_e164' => '+886911222333', 'password' => 'correct-horse-1']);

    $login = $this->postJson('/api/v1/auth/login', ['identifier' => 'new@example.com', 'password' => 'correct-horse-1'])
        ->assertOk()
        ->assertJsonPath('data.user.verification.satisfied', false)
        ->assertJsonPath('data.user.verification.missing', 'email');
    $token = $login->json('data.token');

    $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.verification.missing', 'email');
    $this->withToken($token)->getJson('/api/v1/notices')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'VERIFICATION_REQUIRED')
        ->assertJsonPath('error.fields.verification.0', 'email');
    $this->withToken($token)->patchJson('/api/v1/me/profile', ['display_name' => 'X'])->assertForbidden();
    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    // A verified phone does not help under the email policy.
    $user->forceFill(['phone_verified_at' => now()])->save();
    $this->app['auth']->forgetGuards();
    $this->withToken(memberToken($user))->getJson('/api/v1/notices')->assertForbidden();
});

it('applies a policy switch to members who are already logged in', function () {
    $user = User::factory()->create(['phone_e164' => '+886911222333', 'phone_verified_at' => null]);
    $token = memberToken($user);

    $this->withToken($token)->getJson('/api/v1/notices')->assertOk();

    app(Settings::class)->put(['verification_policy' => Settings::POLICY_PHONE]);
    $this->app['auth']->forgetGuards();

    // V01-A: existing email-verified members retain access after the switch.
    $this->withToken($token)->getJson('/api/v1/notices')->assertOk();
    expect($user->refresh()->verification_grandfathered_at)->not->toBeNull();

    // Verifying the phone (the policy never marks contacts as verified by itself).
    expect($user->refresh()->phone_verified_at)->toBeNull();
    $this->postJson('/api/v1/auth/phone/send-otp', ['phone' => '0911222333'])->assertOk();
    $this->postJson('/api/v1/auth/phone/verify', ['phone' => '0911222333', 'code' => lastOtp()])->assertOk();
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/notices')->assertOk();
});

it('lets an authorized admin switch the policy with confirmation and records the change', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = makeAdmin();
    $this->actingAs($admin);

    $this->get('/admin/verification-policy')->assertOk()->assertSee('email_required');

    // Without real SMS the switch to phone_required needs an explicit acknowledgement.
    Livewire::test(VerificationPolicySettings::class)
        ->callAction('usePhoneRequired', data: ['acknowledge' => false])
        ->assertHasActionErrors(['acknowledge']);
    expect(app(Settings::class)->string('verification_policy'))->toBe(Settings::POLICY_EMAIL);

    Livewire::test(VerificationPolicySettings::class)
        ->callAction('usePhoneRequired', data: ['acknowledge' => true])
        ->assertHasNoActionErrors();

    expect((new Settings)->string('verification_policy'))->toBe(Settings::POLICY_PHONE);

    $event = AuditEvent::query()->where('event', 'member.verification_policy_changed')->firstOrFail();
    expect($event->actor_id)->toBe($admin->id)
        ->and($event->details)->toEqual(['old' => 'email_required', 'new' => 'phone_required'])
        ->and($event->created_at)->not->toBeNull();
});

it('does not let an admin without the settings permission change the policy', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = makeAdmin();
    Role::findByName(User::ADMIN_ROLE, 'web')->revokePermissionTo('settings.manage');
    $this->actingAs($admin->refresh());

    $this->get('/admin/verification-policy')->assertForbidden();
    $this->get('/admin/system-settings')->assertForbidden();

    expect(fn () => (new VerificationPolicySettings)->switchTo(Settings::POLICY_PHONE))
        ->toThrow(HttpException::class);
    expect(app(Settings::class)->string('verification_policy'))->toBe(Settings::POLICY_EMAIL);
});

it('does not reveal whether a phone is registered when sending codes', function () {
    User::factory()->phoneOnly('+886955000111', verified: false)->create();

    // Registered, unverified number and unknown number get the same answers.
    foreach (['0955000111', '0955000999'] as $phone) {
        $this->postJson('/api/v1/auth/phone/send-otp', ['phone' => $phone])
            ->assertOk()
            ->assertJsonPath('data.message', '若此手機尚未驗證，驗證碼已發送。');

        $this->postJson('/api/v1/auth/phone/send-otp', ['phone' => $phone])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'SMS_COOLDOWN')
            ->assertHeader('Retry-After');
    }

    // Only the registered number actually received an SMS.
    expect(fakeSms()->sent)->toHaveCount(1);
});

it('allows only an administrator to revoke grandfathering and records the member audit event', function () {
    $settings = app(Settings::class);
    $settings->put(['verification_policy' => Settings::POLICY_EMAIL]);
    $member = User::factory()->create(['email_verified_at' => now(), 'phone_verified_at' => null]);
    $settings->put(['verification_policy' => Settings::POLICY_PHONE]);

    $page = app(VerificationPolicySettings::class);
    try {
        $page->revokeGrandfathering($member->id);
        $this->fail('Non-admin revocation must be rejected.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = makeAdmin();
    $this->actingAs($admin);
    $page->revokeGrandfathering($member->id);
    $page->revokeGrandfathering($member->id);

    expect($member->fresh()->verification_grandfather_revoked_at)->not->toBeNull()
;
    expect(AuditEvent::query()->where('event', 'member.verification_grandfather_revoked')
        ->where('subject_id', $member->id)->count())->toBe(1);

    $settings->put(['verification_policy' => Settings::POLICY_EMAIL]);
    $settings->put(['verification_policy' => Settings::POLICY_PHONE]);
    expect($member->fresh()->verification_grandfather_revoked_at)->not->toBeNull();
});

