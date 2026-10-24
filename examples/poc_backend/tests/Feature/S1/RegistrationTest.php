<?php

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => '王小明',
        'email' => 'Member@Example.com',
        'phone' => '0912-345-678',
        'password' => 'correct-horse-1',
        'password_confirmation' => 'correct-horse-1',
    ], $overrides);
}

it('requires name, email, phone and password', function () {
    $this->postJson('/api/v1/auth/register', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => ['name', 'email', 'phone', 'password']]]);

    $this->postJson('/api/v1/auth/register', registerPayload(['phone' => '12345']))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['phone']]]);
});

it('registers, sends email verification and verifies via the signed link', function () {
    Notification::fake();

    $response = $this->postJson('/api/v1/auth/register', registerPayload());

    $response->assertCreated()
        ->assertJsonPath('data.user.email', 'member@example.com')
        ->assertJsonPath('data.user.phone', '+886912345678')
        ->assertJsonPath('data.user.display_name', '王小明')
        ->assertJsonPath('data.user.email_verified', false)
        ->assertJsonPath('data.user.verification.policy', Settings::POLICY_EMAIL)
        ->assertJsonPath('data.user.verification.satisfied', false)
        ->assertJsonPath('data.verification.email', 'sent')
        ->assertJsonPath('data.verification.phone', null);

    // Email stage: no SMS is sent automatically.
    expect(fakeSms()->sent)->toBeEmpty();

    $user = User::query()->where('email', 'member@example.com')->firstOrFail();
    expect($user->password)->not->toBe('correct-horse-1');
    expect(AuditEvent::query()->where('event', 'member.registered')->exists())->toBeTrue();

    $url = null;
    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$url) {
        $url = $notification->toMail($user)->actionUrl;

        return true;
    });

    $this->get($url)->assertOk()->assertSee('Email 已驗證');
    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});

it('sends the phone OTP at registration under the phone policy', function () {
    app(Settings::class)->put(['verification_policy' => Settings::POLICY_PHONE]);

    $this->postJson('/api/v1/auth/register', registerPayload())
        ->assertCreated()
        ->assertJsonPath('data.verification.phone', 'sent');

    expect(fakeSms()->sent)->toHaveCount(1);

    $this->postJson('/api/v1/auth/phone/verify', ['phone' => '0912345678', 'code' => lastOtp()])->assertOk();
    expect(User::query()->firstOrFail()->hasVerifiedPhone())->toBeTrue();
});

it('rejects a tampered email verification link', function () {
    $user = User::factory()->unverified()->create();

    $this->get("/email/verify/{$user->id}/".sha1('other@example.com'))->assertForbidden();

    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

it('lets one account log in with either its email or its mobile number', function () {
    User::factory()->create([
        'email' => 'both@example.com',
        'phone_e164' => '+886922000111',
        'phone_verified_at' => now(),
        'password' => 'correct-horse-1',
    ]);

    $this->postJson('/api/v1/auth/login', ['identifier' => 'both@example.com', 'password' => 'correct-horse-1'])->assertOk();
    $this->postJson('/api/v1/auth/login', ['identifier' => '0922000111', 'password' => 'correct-horse-1'])->assertOk();
});

it('rejects duplicate email or phone without naming the other account', function () {
    User::factory()->create(['email' => 'taken@example.com', 'phone_e164' => '+886933000222']);

    $this->postJson('/api/v1/auth/register', registerPayload(['email' => 'taken@example.com', 'phone' => '0933000222']))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['email', 'phone_e164']]]);
});
