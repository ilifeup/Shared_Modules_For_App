<?php

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

it('registers with email, blocks login until verified, then verifies via the signed link', function () {
    Notification::fake();

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => '王小明',
        'email' => 'Member@Example.com',
        'password' => 'correct-horse-1',
        'password_confirmation' => 'correct-horse-1',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.user.email', 'member@example.com')
        ->assertJsonPath('data.user.display_name', '王小明')
        ->assertJsonPath('data.user.email_verified', false)
        ->assertJsonPath('data.verification.email', 'sent');

    $user = User::query()->where('email', 'member@example.com')->firstOrFail();
    expect($user->password)->not->toBe('correct-horse-1');
    expect(AuditEvent::query()->where('event', 'member.registered')->exists())->toBeTrue();

    $this->postJson('/api/v1/auth/login', ['identifier' => 'member@example.com', 'password' => 'correct-horse-1'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

    $url = null;
    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$url) {
        $url = $notification->toMail($user)->actionUrl;

        return true;
    });

    $this->get($url)->assertOk()->assertSee('Email 已驗證');
    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();

    $this->postJson('/api/v1/auth/login', ['identifier' => 'member@example.com', 'password' => 'correct-horse-1'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['token', 'token_type', 'user' => ['id', 'display_name']]]);
});

it('rejects a tampered email verification link', function () {
    $user = User::factory()->unverified()->create();

    $this->get("/email/verify/{$user->id}/".sha1('other@example.com'))->assertForbidden();

    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

it('registers with a mobile number, verifies the SMS OTP and logs in with the number', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => '陳大華',
        'phone' => '0912-345-678',
        'password' => 'correct-horse-1',
        'password_confirmation' => 'correct-horse-1',
    ])->assertCreated()
        ->assertJsonPath('data.user.phone', '+886912345678')
        ->assertJsonPath('data.verification.phone', 'sent');

    expect(fakeSms()->sent)->toHaveCount(1);

    $this->postJson('/api/v1/auth/login', ['identifier' => '0912345678', 'password' => 'correct-horse-1'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'PHONE_NOT_VERIFIED');

    $this->postJson('/api/v1/auth/phone/verify', ['phone' => '+886912345678', 'code' => lastOtp()])
        ->assertOk()
        ->assertJsonPath('data.verified', true);

    $this->postJson('/api/v1/auth/login', ['identifier' => '0912345678', 'password' => 'correct-horse-1'])
        ->assertOk()
        ->assertJsonPath('data.user.phone_verified', true);
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

it('validates registration input with field errors', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'X',
        'email' => 'taken@example.com',
        'password' => 'short',
        'password_confirmation' => 'different',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => ['email', 'password']]]);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'X',
        'phone' => '12345',
        'password' => 'correct-horse-1',
        'password_confirmation' => 'correct-horse-1',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['phone']]]);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'X',
        'password' => 'correct-horse-1',
        'password_confirmation' => 'correct-horse-1',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['email']]]);
});

it('enforces the registration and verification switches from Filament settings', function () {
    app(Settings::class)->put(['registration_email_enabled' => false, 'phone_verification_required' => false]);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'X',
        'email' => 'new@example.com',
        'password' => 'correct-horse-1',
        'password_confirmation' => 'correct-horse-1',
    ])->assertForbidden()->assertJsonPath('error.code', 'EMAIL_REGISTRATION_DISABLED');

    $this->postJson('/api/v1/auth/register', [
        'name' => 'X',
        'phone' => '0933000222',
        'password' => 'correct-horse-1',
        'password_confirmation' => 'correct-horse-1',
    ])->assertCreated()->assertJsonPath('data.verification.phone', 'verified');

    expect(fakeSms()->sent)->toBeEmpty();

    $this->postJson('/api/v1/auth/login', ['identifier' => '0933000222', 'password' => 'correct-horse-1'])->assertOk();
});
