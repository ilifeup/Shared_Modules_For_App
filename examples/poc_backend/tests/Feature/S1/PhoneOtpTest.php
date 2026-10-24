<?php

use App\Models\PhoneOtp;
use App\Models\User;
use App\Support\Settings;

function phoneMember(string $phone = '+886912345678'): User
{
    return User::factory()->phoneOnly($phone, verified: false)->create();
}

function requestOtp(string $phone = '0912345678')
{
    return test()->postJson('/api/v1/auth/phone/send-otp', ['phone' => $phone]);
}

function verifyOtp(string $code, string $phone = '0912345678')
{
    return test()->postJson('/api/v1/auth/phone/verify', ['phone' => $phone, 'code' => $code]);
}

it('stores only a hash of the code and never returns the code', function () {
    phoneMember();

    requestOtp()->assertOk()->assertDontSee(lastOtp());

    $otp = PhoneOtp::query()->firstOrFail();
    expect($otp->code_hash)->not->toContain(lastOtp())
        ->and(strlen($otp->code_hash))->toBe(64)
        ->and($otp->status)->toBe(PhoneOtp::STATUS_SENT);
});

it('counts every wrong attempt and locks the code at the limit', function () {
    app(Settings::class)->put(['otp_max_attempts' => 3]);
    phoneMember();
    requestOtp()->assertOk();
    $code = lastOtp();
    $wrong = $code === '000000' ? '111111' : '000000';

    verifyOtp($wrong)->assertStatus(422)->assertJsonPath('error.code', 'OTP_INVALID');
    expect(PhoneOtp::query()->firstOrFail()->attempts)->toBe(1);

    verifyOtp($wrong)->assertStatus(422);
    verifyOtp($wrong)->assertStatus(422)->assertJsonPath('error.code', 'OTP_LOCKED');

    // Even the correct code no longer works after the lock.
    verifyOtp($code)->assertStatus(422);
    expect(User::query()->firstOrFail()->phone_verified_at)->toBeNull();
});

it('rejects expired codes', function () {
    phoneMember();
    requestOtp()->assertOk();
    $code = lastOtp();

    $this->travel(6)->minutes();

    verifyOtp($code)->assertStatus(422)->assertJsonPath('error.code', 'OTP_EXPIRED');
});

it('accepts a code only once', function () {
    $user = phoneMember();
    requestOtp()->assertOk();
    $code = lastOtp();

    verifyOtp($code)->assertOk();
    verifyOtp($code)->assertStatus(422)->assertJsonPath('error.code', 'OTP_INVALID');

    expect($user->refresh()->phone_verified_at)->not->toBeNull();
});

it('invalidates the previous code when a new one is sent', function () {
    phoneMember();
    requestOtp()->assertOk();
    $first = lastOtp();

    $this->travel(4)->minutes();
    requestOtp()->assertOk();

    if ($first !== lastOtp()) {
        verifyOtp($first)->assertStatus(422);
    }
    verifyOtp(lastOtp())->assertOk();
});

it('enforces the resend cooldown with Retry-After', function () {
    phoneMember();
    requestOtp()->assertOk();

    requestOtp()->assertStatus(429)
        ->assertJsonPath('error.code', 'SMS_COOLDOWN')
        ->assertHeader('Retry-After');

    expect(fakeSms()->sent)->toHaveCount(1);
});

it('enforces the per-phone hourly limit', function () {
    app(Settings::class)->put(['sms_phone_hourly_limit' => 2, 'sms_resend_cooldown_minutes' => 1]);
    phoneMember();

    requestOtp()->assertOk();
    $this->travel(2)->minutes();
    requestOtp()->assertOk();
    $this->travel(2)->minutes();
    requestOtp()->assertStatus(429)->assertJsonPath('error.code', 'SMS_PHONE_LIMIT');

    $this->travel(61)->minutes();
    requestOtp()->assertOk();
});

it('enforces the global hourly limit across phones', function () {
    app(Settings::class)->put(['sms_global_hourly_limit' => 2]);
    phoneMember('+886900000001');
    phoneMember('+886900000002');
    phoneMember('+886900000003');

    requestOtp('0900000001')->assertOk();
    requestOtp('0900000002')->assertOk();
    requestOtp('0900000003')->assertStatus(429)->assertJsonPath('error.code', 'SMS_GLOBAL_LIMIT');

    expect(fakeSms()->sent)->toHaveCount(2);
});

it('does not reveal whether a phone number is registered', function () {
    requestOtp('0911111111')->assertOk();

    expect(fakeSms()->sent)->toBeEmpty();
});

it('reports a provider failure without leaving a usable code', function () {
    // Test mode off + Mitake without credentials: the adapter refuses, nothing is sent.
    app(Settings::class)->put(['sms_test_mode' => false, 'sms_provider' => 'mitake']);
    config(['services.sms.mitake.username' => null, 'services.sms.mitake.password' => null]);
    phoneMember();

    requestOtp()->assertStatus(502)->assertJsonPath('error.code', 'SMS_SEND_FAILED');

    expect(PhoneOtp::query()->firstOrFail()->status)->toBe(PhoneOtp::STATUS_FAILED);

    // A failed send does not start the cooldown or consume the hourly quota.
    app(Settings::class)->put(['sms_test_mode' => true]);
    requestOtp()->assertOk();
});
