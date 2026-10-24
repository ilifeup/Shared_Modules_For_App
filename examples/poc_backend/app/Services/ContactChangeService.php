<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\PhoneOtp;
use App\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * A03 contact changes by the member. Both require the current password.
 * - Email: saved as unverified and a verification link is sent.
 * - Phone: the new number must be verified with a phone-change code first;
 *   only then is it saved (as verified).
 * Also used by old members to add a missing email or phone.
 */
final class ContactChangeService
{
    public function __construct(private readonly PhoneOtpService $otp) {}

    public function changeEmail(User $user, string $email, string $password): void
    {
        $this->checkPassword($user, $password);
        $email = mb_strtolower(trim($email));

        if ($email === $user->email) {
            throw $this->invalid('email', '新 Email 與目前相同。');
        }

        if (User::query()->where('email', $email)->whereKeyNot($user->id)->exists()) {
            throw $this->invalid('email', '此 Email 無法使用，請改用其他 Email。');
        }

        $user->forceFill(['email' => $email, 'email_verified_at' => null])->save();
        $user->sendEmailVerificationNotification();

        AuditEvent::record('member.email_changed', $user->id, $user, ['verification' => 'sent']);
    }

    public function sendPhoneCode(User $user, string $phoneE164, string $password): void
    {
        $this->checkPassword($user, $password);

        if ($phoneE164 === $user->phone_e164 && $user->hasVerifiedPhone()) {
            throw $this->invalid('phone', '新手機號碼與目前相同。');
        }

        $this->ensurePhoneFree($user, $phoneE164);

        $this->otp->send($user, PhoneOtp::PURPOSE_PHONE_CHANGE, $phoneE164);
        AuditEvent::record('member.phone_change_requested', $user->id, $user);
    }

    public function confirmPhone(User $user, string $phoneE164, string $code): void
    {
        $this->otp->verify($user, $code, PhoneOtp::PURPOSE_PHONE_CHANGE, $phoneE164);

        DB::transaction(function () use ($user, $phoneE164): void {
            $this->ensurePhoneFree($user, $phoneE164);
            $user->forceFill(['phone_e164' => $phoneE164, 'phone_verified_at' => now()])->save();
        });

        AuditEvent::record('member.phone_changed', $user->id, $user);
    }

    private function ensurePhoneFree(User $user, string $phoneE164): void
    {
        if (User::query()->where('phone_e164', $phoneE164)->whereKeyNot($user->id)->exists()) {
            throw $this->invalid('phone', '此手機號碼無法使用，請改用其他號碼。');
        }
    }

    private function checkPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            AuditEvent::record('member.contact_change', $user->id, $user, ['reason' => 'wrong_password'], 'failure');

            throw $this->invalid('password', '密碼錯誤。');
        }
    }

    private function invalid(string $field, string $message): ApiException
    {
        return new ApiException(422, 'VALIDATION_FAILED', $message, [$field => [$message]]);
    }
}
