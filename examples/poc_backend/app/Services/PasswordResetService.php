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
        $user = $this->uniqueEmailAccount($email);

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
        $user = $this->uniquePhoneAccount($phoneE164);

        if ($user === null) {
            $this->otp->throttleWithoutSending($phoneE164, PhoneOtp::PURPOSE_PASSWORD_RESET);

            return;
        }

        try {
            $this->otp->send($user, PhoneOtp::PURPOSE_PASSWORD_RESET);
        } catch (ApiException $e) {
            // A provider failure answers like a successful send (and like an unknown number).
            if ($e->errorCode !== 'SMS_SEND_FAILED') {
                throw $e;
            }

            AuditEvent::record('password.reset_requested', $user->id, $user, ['channel' => 'phone'], 'failure');

            return;
        }

        AuditEvent::record('password.reset_requested', $user->id, $user, ['channel' => 'phone']);
    }

    public function resetByPhone(string $phoneE164, string $code, string $password): void
    {
        $user = $this->uniquePhoneAccount($phoneE164);

        if ($user === null) {
            throw PhoneOtpService::invalidCode();
        }

        // Must be a code issued for this password reset; a registration code is rejected.
        // The password change runs in the same transaction that consumes the code (row
        // locked FOR UPDATE): if it fails, the code is not consumed and no token is revoked.
        $this->otp->verify(
            $user,
            $code,
            PhoneOtp::PURPOSE_PASSWORD_RESET,
            detailed: false,
            onVerified: fn () => $this->writePassword($user, $password),
        );

        $this->completed($user, 'phone');
    }

    /**
     * A shared contact cannot identify which account to recover.
     * Never send a reset token or OTP to an arbitrarily selected member.
     */
    private function uniqueEmailAccount(string $email): ?User
    {
        $matches = User::query()->where('email', mb_strtolower(trim($email)))->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function uniquePhoneAccount(string $phone): ?User
    {
        $matches = User::query()->where('phone_e164', $phone)->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function apply(User $user, string $password, string $channel): void
    {
        DB::transaction(fn () => $this->writePassword($user, $password));

        $this->completed($user, $channel);
    }

    /**
     * New password, new remember token and revocation of every Sanctum token.
     * Must run inside a transaction.
     */
    private function writePassword(User $user, string $password): void
    {
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        $user->tokens()->delete();

        if ($user->email !== null) {
            Password::broker()->deleteToken($user);
        }
    }

    private function completed(User $user, string $channel): void
    {
        event(new PasswordReset($user));
        AuditEvent::record('password.reset_completed', $user->id, $user, ['channel' => $channel, 'tokens_revoked' => true]);
    }
}
