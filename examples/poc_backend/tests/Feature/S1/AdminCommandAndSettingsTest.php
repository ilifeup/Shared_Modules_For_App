<?php

use App\Models\User;
use App\Support\Settings;

it('creates exactly one administrator per App', function () {
    $this->artisan('app:create-admin', ['email' => 'Owner@Example.com'])
        ->expectsQuestion('Password (at least 12 characters)', 'a-long-admin-password')
        ->expectsQuestion('Repeat password', 'a-long-admin-password')
        ->assertSuccessful();

    $admin = User::query()->where('email', 'owner@example.com')->firstOrFail();
    expect($admin->isAdmin())->toBeTrue()
        ->and($admin->hasVerifiedEmail())->toBeTrue()
        ->and($admin->password)->not->toBe('a-long-admin-password');

    $this->artisan('app:create-admin', ['email' => 'second@example.com'])->assertFailed();
    expect(User::query()->where('email', 'second@example.com')->exists())->toBeFalse();
});

it('rejects a short administrator password', function () {
    $this->artisan('app:create-admin', ['email' => 'owner@example.com'])
        ->expectsQuestion('Password (at least 12 characters)', 'short')
        ->expectsQuestion('Repeat password', 'short')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('has the approved defaults', function () {
    $settings = app(Settings::class);

    expect($settings->bool('member_long_login'))->toBeTrue()
        ->and($settings->int('admin_idle_minutes'))->toBe(10080)
        ->and($settings->bool('sms_test_mode'))->toBeTrue()
        ->and($settings->int('sms_global_hourly_limit'))->toBe(100)
        ->and($settings->int('sms_phone_hourly_limit'))->toBe(3)
        ->and($settings->int('sms_resend_cooldown_minutes'))->toBe(3)
        ->and($settings->int('otp_ttl_minutes'))->toBe(5)
        ->and($settings->int('otp_max_attempts'))->toBe(5);
});

it('rejects invalid or unknown settings', function () {
    $settings = app(Settings::class);

    expect(fn () => $settings->put(['admin_idle_minutes' => 0]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $settings->put(['sms_provider' => 'unknown']))->toThrow(InvalidArgumentException::class);
    expect(fn () => $settings->put(['no_such_key' => 1]))->toThrow(InvalidArgumentException::class);
    expect($settings->int('admin_idle_minutes'))->toBe(10080);
});
