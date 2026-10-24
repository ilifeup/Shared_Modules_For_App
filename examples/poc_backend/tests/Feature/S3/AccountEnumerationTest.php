<?php

use App\Models\PhoneOtp;
use App\Models\User;
use App\Support\Settings;

/**
 * S3.1 review: public OTP endpoints must not reveal whether a number belongs to an
 * account (registered unverified, registered verified, unknown) through the HTTP
 * status, error code, Retry-After, the global limit or a provider failure.
 */
const ENUM_UNVERIFIED = '0988000111';
const ENUM_VERIFIED = '0988000222';
const ENUM_UNKNOWN = '0988000333';

beforeEach(function () {
    // Retry-After is in whole seconds; freeze the clock so answers are comparable.
    $this->freezeTime();
    User::factory()->phoneOnly('+886988000111', verified: false)->create();
    User::factory()->phoneOnly('+886988000222', verified: true)->create();
});

/**
 * @return array{0: int, 1: string|null, 2: string|null, 3: mixed}
 */
function answer($response): array
{
    return [
        $response->status(),
        $response->json('error.code'),
        $response->headers->get('Retry-After'),
        $response->json('data'),
    ];
}

function sendCode(string $endpoint, string $phone)
{
    return test()->postJson($endpoint, ['phone' => $phone]);
}

dataset('send endpoints', [
    'phone verification' => ['/api/v1/auth/phone/send-otp'],
    'password reset' => ['/api/v1/auth/password/phone/send-otp'],
]);

it('answers send, cooldown and Retry-After identically for every number state', function (string $endpoint) {
    $first = [];
    $second = [];
    foreach ([ENUM_UNVERIFIED, ENUM_VERIFIED, ENUM_UNKNOWN] as $phone) {
        $first[] = answer(sendCode($endpoint, $phone));
        $second[] = answer(sendCode($endpoint, $phone));
    }

    expect(array_unique(array_map('serialize', $first)))->toHaveCount(1)
        ->and($first[0][0])->toBe(200)
        ->and(array_unique(array_map('serialize', $second)))->toHaveCount(1)
        ->and($second[0][0])->toBe(429)
        ->and($second[0][1])->toBe('SMS_COOLDOWN')
        ->and($second[0][2])->not->toBeNull();
})->with('send endpoints');

it('applies the cooldown across purposes for every number state', function () {
    foreach ([ENUM_UNVERIFIED, ENUM_VERIFIED, ENUM_UNKNOWN] as $phone) {
        sendCode('/api/v1/auth/phone/send-otp', $phone)->assertOk();
        sendCode('/api/v1/auth/password/phone/send-otp', $phone)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'SMS_COOLDOWN');
    }
});

it('applies the per-number hourly limit identically', function () {
    app(Settings::class)->put(['sms_phone_hourly_limit' => 2, 'sms_resend_cooldown_minutes' => 1]);

    $answers = [];
    foreach (range(1, 3) as $round) {
        foreach ([ENUM_UNVERIFIED, ENUM_VERIFIED, ENUM_UNKNOWN] as $phone) {
            $answers[$round][] = answer(sendCode('/api/v1/auth/password/phone/send-otp', $phone));
        }
        $this->travel(2)->minutes();
    }

    foreach ($answers as $round => $perNumber) {
        expect(array_unique(array_map('serialize', $perNumber)))->toHaveCount(1);
    }
    expect($answers[3][0][1])->toBe('SMS_PHONE_LIMIT');
});

it('reports the global limit for unknown numbers too', function () {
    app(Settings::class)->put(['sms_global_hourly_limit' => 1]);
    User::factory()->phoneOnly('+886988000444', verified: false)->create();
    sendCode('/api/v1/auth/phone/send-otp', '0988000444')->assertOk();
    expect(fakeSms()->sent)->toHaveCount(1);

    $answers = [];
    foreach ([ENUM_UNVERIFIED, ENUM_VERIFIED, ENUM_UNKNOWN] as $phone) {
        $answers[] = answer(sendCode('/api/v1/auth/password/phone/send-otp', $phone));
    }

    expect(array_unique(array_map('serialize', $answers)))->toHaveCount(1)
        ->and($answers[0][1])->toBe('SMS_GLOBAL_LIMIT')
        ->and(fakeSms()->sent)->toHaveCount(1);
});

it('answers a provider failure like a send to an unknown number', function () {
    app(Settings::class)->put(['sms_test_mode' => false, 'sms_provider' => 'mitake']);
    config(['services.sms.mitake.username' => null, 'services.sms.mitake.password' => null]);

    $registered = answer(sendCode('/api/v1/auth/password/phone/send-otp', ENUM_VERIFIED));
    $unknown = answer(sendCode('/api/v1/auth/password/phone/send-otp', ENUM_UNKNOWN));

    expect($registered)->toBe($unknown)
        ->and($registered[0])->toBe(200)
        ->and(PhoneOtp::query()->firstOrFail()->status)->toBe(PhoneOtp::STATUS_FAILED);
});

it('answers wrong, expired and locked codes like unknown numbers', function () {
    app(Settings::class)->put(['otp_max_attempts' => 2]);
    sendCode('/api/v1/auth/password/phone/send-otp', ENUM_VERIFIED)->assertOk();
    $code = lastOtp();
    $wrong = $code === '000000' ? '111111' : '000000';
    $body = ['password' => 'new-password-1', 'password_confirmation' => 'new-password-1'];

    $reset = fn (string $phone, string $c) => answer(test()->postJson('/api/v1/auth/password/phone/reset', $body + ['phone' => $phone, 'code' => $c]));
    $verify = fn (string $phone, string $c) => answer(test()->postJson('/api/v1/auth/phone/verify', ['phone' => $phone, 'code' => $c]));

    $unknownReset = $reset(ENUM_UNKNOWN, $wrong);
    expect($unknownReset[1])->toBe('OTP_INVALID');

    // Wrong code, then the attempt that locks the code.
    expect($reset(ENUM_VERIFIED, $wrong))->toBe($unknownReset)
        ->and($reset(ENUM_VERIFIED, $wrong))->toBe($unknownReset)
        ->and(PhoneOtp::query()->firstOrFail()->status)->toBe(PhoneOtp::STATUS_LOCKED);

    // Expired code.
    $this->travel(4)->minutes();
    sendCode('/api/v1/auth/password/phone/send-otp', ENUM_VERIFIED)->assertOk();
    $this->travel(6)->minutes();
    expect($reset(ENUM_VERIFIED, lastOtp()))->toBe($unknownReset);

    // Phone verification endpoint: unverified, verified and unknown numbers.
    $unknownVerify = $verify(ENUM_UNKNOWN, $wrong);
    expect($verify(ENUM_VERIFIED, $wrong))->toBe($unknownVerify)
        ->and($verify(ENUM_UNVERIFIED, $wrong))->toBe($unknownVerify)
        ->and($unknownVerify[1])->toBe('OTP_INVALID');
});
