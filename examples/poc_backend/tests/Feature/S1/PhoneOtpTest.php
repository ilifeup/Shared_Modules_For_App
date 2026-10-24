<?php

use App\Models\AppSetting;
use App\Models\AuditEvent;
use App\Models\PhoneOtp;
use App\Models\User;
use App\Services\PhoneOtpService;
use App\Services\Sms\SmsGatewayFactory;
use App\Support\Settings;
use EachCore\SharedCore\SmsProvider;
use EachCore\SharedCore\SmsSendResult;

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
    // Public endpoint: a locked code answers like any invalid code (S3.1 enumeration fix).
    verifyOtp($wrong)->assertStatus(422)->assertJsonPath('error.code', 'OTP_INVALID');
    expect(PhoneOtp::query()->firstOrFail()->status)->toBe(PhoneOtp::STATUS_LOCKED);

    // Even the correct code no longer works after the lock.
    verifyOtp($code)->assertStatus(422);
    expect(User::query()->firstOrFail()->phone_verified_at)->toBeNull();
});

it('rejects expired codes', function () {
    phoneMember();
    requestOtp()->assertOk();
    $code = lastOtp();

    $this->travel(6)->minutes();

    verifyOtp($code)->assertStatus(422)->assertJsonPath('error.code', 'OTP_INVALID');
    expect(PhoneOtp::query()->firstOrFail()->status)->toBe(PhoneOtp::STATUS_EXPIRED);
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

    // Public endpoint: answered like a successful send (and like an unknown number).
    requestOtp()->assertOk()->assertJsonPath('data.message', '若此手機尚未驗證，驗證碼已發送。');

    expect(PhoneOtp::query()->firstOrFail()->status)->toBe(PhoneOtp::STATUS_FAILED)
        ->and(AuditEvent::query()->where('event', 'sms.send_failed')->where('result', 'failure')->exists())->toBeTrue();

    // The failed code is not counted by the database limits; the per-number cooldown
    // applies as for any number, then a new code can be sent.
    app(Settings::class)->put(['sms_test_mode' => true]);
    requestOtp()->assertStatus(429)->assertJsonPath('error.code', 'SMS_COOLDOWN');
    $this->travel(4)->minutes();
    requestOtp()->assertOk();
    expect(PhoneOtp::query()->whereIn('status', PhoneOtp::COUNTED_STATUSES)->count())->toBe(1);
});

it('enforces limits with a database lock, independent of the cache driver', function () {
    // S2: issuance must not rely on cache locks (deployments may use a non-shared cache).
    config(['cache.default' => 'null']);
    phoneMember();

    requestOtp()->assertOk();
    requestOtp()->assertStatus(429)->assertJsonPath('error.code', 'SMS_COOLDOWN');

    expect(fakeSms()->sent)->toHaveCount(1);
    expect(AppSetting::query()->whereKey(PhoneOtpService::ISSUE_LOCK_KEY)->exists())->toBeTrue();
});

/**
 * A provider double that simulates a newer request superseding the code while
 * this request is still waiting for the provider's reply.
 */
function slowProviderSupersededMeanwhile(bool $accepted): void
{
    app()->instance(SmsGatewayFactory::class, new class(app(Settings::class), $accepted) extends SmsGatewayFactory
    {
        public function __construct(Settings $settings, private readonly bool $accepted)
        {
            parent::__construct($settings);
        }

        public function make(): SmsProvider
        {
            $accepted = $this->accepted;

            return new class($accepted) implements SmsProvider
            {
                public function __construct(private readonly bool $accepted) {}

                public function sendOtp(string $phoneE164, string $message): SmsSendResult
                {
                    PhoneOtp::query()->whereNull('used_at')->update([
                        'status' => PhoneOtp::STATUS_SUPERSEDED,
                        'used_at' => now(),
                    ]);

                    return $this->accepted
                        ? new SmsSendResult(true, 'slow', 'late-reply')
                        : SmsSendResult::rejected('slow', 'TIMEOUT');
                }
            };
        }
    });
}

it('does not revive a superseded code when a slow provider reply arrives late', function () {
    phoneMember();
    slowProviderSupersededMeanwhile(accepted: true);

    requestOtp()->assertOk();

    $otp = PhoneOtp::query()->firstOrFail();
    expect($otp->status)->toBe(PhoneOtp::STATUS_SUPERSEDED)
        ->and($otp->used_at)->not->toBeNull()
        ->and($otp->provider_message_id)->toBeNull();

    // Nothing usable is left for this phone.
    verifyOtp('123456')->assertStatus(422)->assertJsonPath('error.code', 'OTP_INVALID');
});

it('does not overwrite a superseded code when a slow provider call fails late', function () {
    phoneMember();
    slowProviderSupersededMeanwhile(accepted: false);

    // The service reports the failure; the public endpoint answers generically.
    requestOtp()->assertOk();

    expect(PhoneOtp::query()->firstOrFail()->status)->toBe(PhoneOtp::STATUS_SUPERSEDED);
});
