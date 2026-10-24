<?php

namespace App\Services;

use App\Models\PhoneOtp;
use App\Models\User;
use App\Services\Sms\SmsGatewayFactory;
use App\Support\Api\ApiException;
use App\Support\Settings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phone OTP: generation, hashed storage, sending, verification, expiry,
 * single use, attempt limits, hourly limits and resend cooldown.
 *
 * Limits are counted from phone_otps rows under a cache lock, so parallel
 * requests cannot both pass the checks. Verification locks the OTP row
 * (SELECT ... FOR UPDATE) so a code cannot be used twice.
 */
final class PhoneOtpService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly SmsGatewayFactory $gateways,
    ) {}

    public function send(User $user): void
    {
        $phone = $user->phone_e164;

        if ($phone === null) {
            throw new ApiException(422, 'PHONE_MISSING', '此帳號沒有手機號碼。');
        }

        try {
            [$otp, $code] = Cache::lock('phone-otp-issue', 10)->block(5, fn () => $this->issue($user, $phone));
        } catch (LockTimeoutException) {
            throw new ApiException(503, 'SMS_BUSY', '簡訊服務忙碌中，請稍後再試。');
        }

        $gateway = $this->gateways->make();
        $minutes = $this->settings->int('otp_ttl_minutes');
        $result = $gateway->sendOtp($phone, '【'.config('app.name')."】驗證碼 {$code}，{$minutes} 分鐘內有效。請勿提供給他人。");

        if (! $result->accepted) {
            $otp->forceFill(['status' => PhoneOtp::STATUS_FAILED, 'used_at' => now(), 'provider' => $result->provider])->save();

            report(new RuntimeException("SMS send failed via {$result->provider}: {$result->errorCode}"));

            throw new ApiException(502, 'SMS_SEND_FAILED', '簡訊發送失敗，請稍後再試。');
        }

        $otp->forceFill([
            'status' => PhoneOtp::STATUS_SENT,
            'provider' => $result->provider,
            'provider_message_id' => $result->providerMessageId,
        ])->save();
    }

    public function verify(User $user, string $code): void
    {
        $outcome = DB::transaction(function () use ($user, $code): string {
            /** @var PhoneOtp|null $otp */
            $otp = PhoneOtp::query()
                ->where('user_id', $user->id)
                ->where('phone_e164', $user->phone_e164)
                ->where('status', PhoneOtp::STATUS_SENT)
                ->whereNull('used_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($otp === null) {
                return 'invalid';
            }

            if ($otp->expires_at->isPast()) {
                $otp->forceFill(['status' => PhoneOtp::STATUS_EXPIRED, 'used_at' => now()])->save();

                return 'expired';
            }

            $otp->attempts++;

            if (! hash_equals($otp->code_hash, $this->hash($code, $otp->phone_e164))) {
                if ($otp->attempts >= $this->settings->int('otp_max_attempts')) {
                    $otp->forceFill(['status' => PhoneOtp::STATUS_LOCKED, 'used_at' => now()]);
                }

                // Saved (and committed) before the error is returned, so failed attempts always count.
                $otp->save();

                return $otp->status === PhoneOtp::STATUS_LOCKED ? 'locked' : 'invalid';
            }

            $otp->forceFill(['status' => PhoneOtp::STATUS_USED, 'used_at' => now()])->save();
            $user->forceFill(['phone_verified_at' => now()])->save();

            return 'ok';
        });

        match ($outcome) {
            'ok' => null,
            'expired' => throw new ApiException(422, 'OTP_EXPIRED', '驗證碼已過期，請重新取得。'),
            'locked' => throw new ApiException(422, 'OTP_LOCKED', '錯誤次數過多，請重新取得驗證碼。'),
            default => throw new ApiException(422, 'OTP_INVALID', '驗證碼錯誤或已失效。'),
        };
    }

    /**
     * Runs under the issue lock.
     *
     * @return array{0: PhoneOtp, 1: string}
     */
    private function issue(User $user, string $phone): array
    {
        $now = now();
        $hourAgo = $now->copy()->subHour();

        $latest = PhoneOtp::query()
            ->where('phone_e164', $phone)
            ->whereIn('status', PhoneOtp::COUNTED_STATUSES)
            ->latest('id')
            ->first();

        $cooldownSeconds = $this->settings->int('sms_resend_cooldown_minutes') * 60;

        if ($latest !== null && $latest->created_at->gt($now->copy()->subSeconds($cooldownSeconds))) {
            $retryAfter = max(1, $cooldownSeconds - (int) $latest->created_at->diffInSeconds($now, true));

            throw new ApiException(429, 'SMS_COOLDOWN', "請於 {$retryAfter} 秒後再重新發送。", [], ['Retry-After' => (string) $retryAfter]);
        }

        $phoneCount = PhoneOtp::query()
            ->where('phone_e164', $phone)
            ->whereIn('status', PhoneOtp::COUNTED_STATUSES)
            ->where('created_at', '>=', $hourAgo)
            ->count();

        if ($phoneCount >= $this->settings->int('sms_phone_hourly_limit')) {
            throw new ApiException(429, 'SMS_PHONE_LIMIT', '此門號本小時的簡訊次數已達上限。');
        }

        $globalCount = PhoneOtp::query()
            ->whereIn('status', PhoneOtp::COUNTED_STATUSES)
            ->where('created_at', '>=', $hourAgo)
            ->count();

        if ($globalCount >= $this->settings->int('sms_global_hourly_limit')) {
            throw new ApiException(429, 'SMS_GLOBAL_LIMIT', '系統本小時的簡訊發送量已達上限，請稍後再試。');
        }

        // A new code invalidates any earlier unused code for this phone.
        PhoneOtp::query()
            ->where('phone_e164', $phone)
            ->whereNull('used_at')
            ->update(['status' => PhoneOtp::STATUS_SUPERSEDED, 'used_at' => $now]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $otp = PhoneOtp::query()->create([
            'user_id' => $user->id,
            'phone_e164' => $phone,
            'code_hash' => $this->hash($code, $phone),
            'status' => PhoneOtp::STATUS_PENDING,
            'expires_at' => $now->copy()->addMinutes($this->settings->int('otp_ttl_minutes')),
        ]);

        return [$otp, $code];
    }

    private function hash(string $code, string $phone): string
    {
        return hash_hmac('sha256', $phone.'|'.$code, (string) config('app.key'));
    }
}
