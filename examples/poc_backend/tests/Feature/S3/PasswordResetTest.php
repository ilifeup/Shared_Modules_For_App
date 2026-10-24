<?php

use App\Models\AuditEvent;
use App\Models\PhoneOtp;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

function resetMember(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'reset@example.com',
        'phone_e164' => '+886966000111',
        'phone_verified_at' => now(),
        'password' => 'old-password-1',
    ], $attributes));
}

function capturedResetToken(User $user): string
{
    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    return (string) $token;
}

function newPassword(array $extra = []): array
{
    return array_merge(['password' => 'new-password-1', 'password_confirmation' => 'new-password-1'], $extra);
}

it('answers forgot-password the same way for registered and unknown emails', function () {
    Notification::fake();
    $user = resetMember();

    $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'reset@example.com'])->assertOk();
    $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com'])->assertOk();

    expect($known->json('data'))->toBe($unknown->json('data'));
    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertSentTimes(ResetPassword::class, 1);
});

it('resets by email once, revokes every token and rejects reuse', function () {
    Notification::fake();
    $user = resetMember();
    $phoneToken = $user->createToken('phone')->plainTextToken;
    $tabletToken = $user->createToken('tablet')->plainTextToken;

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'reset@example.com'])->assertOk();
    $token = capturedResetToken($user);

    $this->postJson('/api/v1/auth/password/reset', newPassword(['email' => 'reset@example.com', 'token' => $token]))
        ->assertOk()
        ->assertJsonPath('data.reset', true);

    expect($user->tokens()->count())->toBe(0);
    $this->app['auth']->forgetGuards();
    $this->withToken($phoneToken)->getJson('/api/v1/me')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($tabletToken)->getJson('/api/v1/me')->assertUnauthorized();

    $this->postJson('/api/v1/auth/login', ['identifier' => 'reset@example.com', 'password' => 'old-password-1'])->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['identifier' => 'reset@example.com', 'password' => 'new-password-1'])->assertOk();

    // Single use.
    $this->postJson('/api/v1/auth/password/reset', newPassword(['email' => 'reset@example.com', 'token' => $token, 'password' => 'third-password-1', 'password_confirmation' => 'third-password-1']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'RESET_TOKEN_INVALID');
});

it('rejects an expired or wrong email reset token', function () {
    Notification::fake();
    $user = resetMember();

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'reset@example.com'])->assertOk();
    $token = capturedResetToken($user);

    $this->postJson('/api/v1/auth/password/reset', newPassword(['email' => 'reset@example.com', 'token' => 'not-the-token']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'RESET_TOKEN_INVALID');

    $this->travel(61)->minutes();

    $this->postJson('/api/v1/auth/password/reset', newPassword(['email' => 'reset@example.com', 'token' => $token]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'RESET_TOKEN_INVALID');

    $this->postJson('/api/v1/auth/login', ['identifier' => 'reset@example.com', 'password' => 'old-password-1'])->assertOk();
});

it('resets through the web form linked from the email', function () {
    Notification::fake();
    $user = resetMember();

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'reset@example.com'])->assertOk();
    $token = capturedResetToken($user);

    $url = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$url) {
        $url = $notification->toMail($user)->actionUrl;

        return true;
    });
    expect($url)->toContain('/password/reset/'.$token);

    $this->get('/password/reset/'.$token.'?email=reset%40example.com')->assertOk()->assertSee('重設密碼');

    $this->post('/password/reset', newPassword(['email' => 'reset@example.com', 'token' => $token]))
        ->assertOk()
        ->assertSee('密碼已重設');

    $this->postJson('/api/v1/auth/login', ['identifier' => 'reset@example.com', 'password' => 'new-password-1'])->assertOk();
});

it('resets by phone with a reset-purpose code and revokes every token', function () {
    $user = resetMember();
    $token = $user->createToken('phone')->plainTextToken;

    $this->postJson('/api/v1/auth/password/phone/send-otp', ['phone' => '0966000111'])->assertOk();
    expect(fakeSms()->sent)->toHaveCount(1)
        ->and(end(fakeSms()->sent)['message'])->toContain('密碼重設');
    $code = lastOtp();

    $this->postJson('/api/v1/auth/password/phone/reset', newPassword(['phone' => '0966000111', 'code' => $code]))
        ->assertOk();

    expect($user->tokens()->count())->toBe(0);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['identifier' => '0966000111', 'password' => 'new-password-1'])->assertOk();

    // Reuse of the same code fails.
    $this->postJson('/api/v1/auth/password/phone/reset', newPassword(['phone' => '0966000111', 'code' => $code, 'password' => 'third-password-1', 'password_confirmation' => 'third-password-1']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'OTP_INVALID');
});

it('never accepts a registration code for a password reset', function () {
    $user = resetMember(['phone_verified_at' => null]);

    $this->postJson('/api/v1/auth/phone/send-otp', ['phone' => '0966000111'])->assertOk();
    $registrationCode = lastOtp();
    expect(PhoneOtp::query()->latest('id')->firstOrFail()->purpose)->toBe(PhoneOtp::PURPOSE_VERIFY);

    $this->postJson('/api/v1/auth/password/phone/reset', newPassword(['phone' => '0966000111', 'code' => $registrationCode]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'OTP_INVALID');

    // An earlier verified phone is not enough either: a fresh reset code is required.
    $user->forceFill(['phone_verified_at' => now()])->save();
    $this->postJson('/api/v1/auth/password/phone/reset', newPassword(['phone' => '0966000111', 'code' => '000000']))
        ->assertStatus(422);

    $this->postJson('/api/v1/auth/login', ['identifier' => '0966000111', 'password' => 'old-password-1'])->assertOk();

    // And a reset code does not verify the phone or survive as a registration code.
    $user->forceFill(['phone_verified_at' => null])->save();
    $this->travel(4)->minutes();
    $this->postJson('/api/v1/auth/password/phone/send-otp', ['phone' => '0966000111'])->assertOk();
    $resetCode = lastOtp();
    $this->postJson('/api/v1/auth/phone/verify', ['phone' => '0966000111', 'code' => $resetCode])->assertStatus(422);
    expect($user->refresh()->phone_verified_at)->toBeNull();
});

it('rejects wrong and expired phone reset codes', function () {
    resetMember();

    $this->postJson('/api/v1/auth/password/phone/send-otp', ['phone' => '0966000111'])->assertOk();
    $code = lastOtp();
    $wrong = $code === '123456' ? '654321' : '123456';

    $this->postJson('/api/v1/auth/password/phone/reset', newPassword(['phone' => '0966000111', 'code' => $wrong]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'OTP_INVALID');

    $this->travel(6)->minutes();

    $this->postJson('/api/v1/auth/password/phone/reset', newPassword(['phone' => '0966000111', 'code' => $code]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'OTP_INVALID');

    $this->postJson('/api/v1/auth/login', ['identifier' => '0966000111', 'password' => 'old-password-1'])->assertOk();
});

it('answers phone reset requests the same way for unknown numbers', function () {
    resetMember();

    foreach (['0966000111', '0966000999'] as $phone) {
        $this->postJson('/api/v1/auth/password/phone/send-otp', ['phone' => $phone])
            ->assertOk()
            ->assertJsonPath('data.message', '若此手機已註冊，密碼重設驗證碼已發送。');
        $this->postJson('/api/v1/auth/password/phone/send-otp', ['phone' => $phone])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'SMS_COOLDOWN');
    }

    expect(fakeSms()->sent)->toHaveCount(1);

    $this->postJson('/api/v1/auth/password/phone/reset', newPassword(['phone' => '0966000999', 'code' => '123456']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'OTP_INVALID');
});

it('keeps passwords, codes and tokens out of the audit log', function () {
    Notification::fake();
    $user = resetMember();

    $this->postJson('/api/v1/auth/login', ['identifier' => 'reset@example.com', 'password' => 'wrong-password'])->assertUnauthorized();
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'reset@example.com']);
    $token = capturedResetToken($user);
    $this->postJson('/api/v1/auth/password/reset', newPassword(['email' => 'reset@example.com', 'token' => $token]))->assertOk();
    $this->travel(4)->minutes();
    $this->postJson('/api/v1/auth/password/phone/send-otp', ['phone' => '0966000111'])->assertOk();
    $code = lastOtp();
    $this->postJson('/api/v1/auth/password/phone/reset', newPassword(['phone' => '0966000111', 'code' => $code, 'password' => 'third-password-1', 'password_confirmation' => 'third-password-1']))->assertOk();

    // Forbidden keys are stripped even if a caller passes them.
    AuditEvent::record('test.event', $user->id, null, ['password' => 'x', 'token' => 'y', 'code' => 'z', 'note' => 'kept']);

    $dump = json_encode(AuditEvent::query()->get()->map->getAttributes()->all(), JSON_UNESCAPED_UNICODE);
    foreach (['wrong-password', 'old-password-1', 'new-password-1', 'third-password-1', $token, $code] as $secret) {
        expect($dump)->not->toContain($secret);
    }
    expect(AuditEvent::query()->where('event', 'test.event')->firstOrFail()->details)->toBe(['note' => 'kept'])
        ->and(AuditEvent::query()->where('event', 'password.reset_completed')->count())->toBe(2)
        ->and(AuditEvent::query()->where('event', 'member.login')->where('result', 'failure')->count())->toBe(1);
});

it('limits each public auth endpoint separately', function () {
    resetMember();

    foreach (range(1, 3) as $_) {
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com'])->assertOk();
    }
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED');

    // Other endpoints keep their own budget.
    $this->postJson('/api/v1/auth/login', ['identifier' => 'reset@example.com', 'password' => 'old-password-1'])->assertOk();
});

it('keeps the reset code, password and tokens when saving the new password fails', function () {
    $user = resetMember();
    $token = $user->createToken('phone')->plainTextToken;

    $this->postJson('/api/v1/auth/password/phone/send-otp', ['phone' => '0966000111'])->assertOk();
    $code = lastOtp();

    // Simulated database failure while the new password is written.
    $fail = true;
    User::saving(function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('simulated database failure');
        }
    });

    $this->postJson('/api/v1/auth/password/phone/reset', newPassword(['phone' => '0966000111', 'code' => $code]))
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'SERVER_ERROR');

    // Everything rolled back: code still usable, old password and tokens still valid.
    $otp = PhoneOtp::query()->where('purpose', PhoneOtp::PURPOSE_PASSWORD_RESET)->firstOrFail();
    expect($otp->status)->toBe(PhoneOtp::STATUS_SENT)
        ->and($otp->used_at)->toBeNull()
        ->and($user->tokens()->count())->toBe(1)
        ->and(AuditEvent::query()->where('event', 'password.reset_completed')->exists())->toBeFalse();
    $this->postJson('/api/v1/auth/login', ['identifier' => '0966000111', 'password' => 'old-password-1'])->assertOk();

    // Once the database works again the same code completes the reset.
    $fail = false;
    $this->postJson('/api/v1/auth/password/phone/reset', newPassword(['phone' => '0966000111', 'code' => $code]))->assertOk();
    expect($user->tokens()->count())->toBe(0)
        ->and($otp->refresh()->status)->toBe(PhoneOtp::STATUS_USED);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

it('completes a repeated phone reset submission only once', function () {
    $user = resetMember();
    $this->postJson('/api/v1/auth/password/phone/send-otp', ['phone' => '0966000111'])->assertOk();
    $request = newPassword(['phone' => '0966000111', 'code' => lastOtp()]);

    $this->postJson('/api/v1/auth/password/phone/reset', $request)->assertOk();
    $user->createToken('after-reset');
    $this->postJson('/api/v1/auth/password/phone/reset', $request)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'OTP_INVALID');

    // The second submission revoked nothing and recorded nothing.
    expect($user->tokens()->count())->toBe(1)
        ->and(AuditEvent::query()->where('event', 'password.reset_completed')->count())->toBe(1);
});
