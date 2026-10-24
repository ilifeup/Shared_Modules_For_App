<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\PhoneOtp;
use App\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * A01 password reset by email (Laravel password broker: expiring, single-use,
 * hashed token) or by phone (purpose-bound OTP). After any reset every Sanctum
 * token of the account is revoked, so the member must log in again.
 */
final class PasswordResetService
{
    public function __construct(private readonly PhoneOtpService $otp) {}

    /**
     * Sends a reset link when the email belongs to an account. Callers always
     * answer with the same generic message.
     */
    public function requestByEmail(string $email): void
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        if ($user === null) {
            return;
        }

        $status = Password::broker()->sendResetLink(['email' => $user->email]);
        AuditEvent::record('password.reset_requested', $user->id, $user, ['channel' => 'email'], $status === Password::RESET_LINK_SENT ? 'success' : 'throttled');
    }

    public function resetByEmail(string $email, string $token, string $password): void
    {
        $status = Password::broker()->reset(
            ['email' => mb_strtolower(trim($email)), 'token' => $token, 'password' => $password],
            fn (User $user) => $this->apply($user, $password, 'email'),
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new ApiException(422, 'RESET_TOKEN_INVALID', '重設連結無效或已過期，請重新申請。');
        }
    }

    public function requestByPhone(string $phoneE164): void
    {
        $user = User::query()->where('phone_e164', $phoneE164)->first();

        if ($user === null) {
            $this->otp->throttleWithoutSending($phoneE164, PhoneOtp::PURPOSE_PASSWORD_RESET);

            return;
        }

        $this->otp->send($user, PhoneOtp::PURPOSE_PASSWORD_RESET);
        AuditEvent::record('password.reset_requested', $user->id, $user, ['channel' => 'phone']);
    }

    public function resetByPhone(string $phoneE164, string $code, string $password): void
    {
        $user = User::query()->where('phone_e164', $phoneE164)->first();

        if ($user === null) {
            throw new ApiException(422, 'OTP_INVALID', '驗證碼錯誤或已失效。');
        }

        // Must be a code issued for this password reset; a registration code is rejected.
        $this->otp->verify($user, $code, PhoneOtp::PURPOSE_PASSWORD_RESET);

        $this->apply($user, $password, 'phone');
    }

    private function apply(User $user, string $password, string $channel): void
    {
        DB::transaction(function () use ($user, $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();

            if ($user->email !== null) {
                Password::broker()->deleteToken($user);
            }
        });

        event(new PasswordReset($user));
        AuditEvent::record('password.reset_completed', $user->id, $user, ['channel' => $channel, 'tokens_revoked' => true]);
    }
}
