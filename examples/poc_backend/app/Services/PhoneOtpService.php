<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\PhoneOtp;
use App\Models\User;
use App\Services\Sms\SmsGatewayFactory;
use App\Support\Api\ApiException;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Phone OTP: generation, hashed storage, sending, verification, expiry,
 * single use, attempt limits, hourly limits and resend cooldown.
 *
 * Limits are counted from phone_otps rows while holding a MySQL row lock
 * (SELECT ... FOR UPDATE on a dedicated app_settings row), so parallel requests
 * cannot both pass the checks — on any number of app instances and with any
 * cache driver. Verification locks the OTP row so a code cannot be used twice.
 */
final class PhoneOtpService
{
    /** app_settings row used only as a database mutex for OTP issuance. */
    public const ISSUE_LOCK_KEY = '_lock_phone_otp_issue';

    public function __construct(
        private readonly Settings $settings,
        private readonly SmsGatewayFactory $gateways,
    ) {}

    /**
     * Issues and sends a code. $purpose isolates codes (a registration code can never
     * reset a password); $phone overrides the target number (phone change).
     */
    public function send(User $user, string $purpose = PhoneOtp::PURPOSE_VERIFY, ?string $phone = null): void
    {
        $phone ??= $user->phone_e164;

        if ($phone === null) {
            throw new ApiException(422, 'PHONE_MISSING', '此帳號沒有手機號碼。');
        }

        // Same per-number answers as throttleWithoutSending(), whatever the account state.
        $this->throttlePerNumber($phone);

        // Ensure the mutex row exists *outside* the transaction: an INSERT IGNORE on an
        // existing row takes a shared lock, and two transactions holding it would
        // deadlock when both upgrade to FOR UPDATE.
        AppSetting::query()->insertOrIgnore([
            'key' => self::ISSUE_LOCK_KEY,
            'value' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$otp, $code] = DB::transaction(function () use ($user, $phone, $purpose): array {
            // Lock first, before any plain SELECT, so the counts below (REPEATABLE READ
            // snapshot taken after the lock) include rows committed by the previous holder.
            AppSetting::query()->whereKey(self::ISSUE_LOCK_KEY)->lockForUpdate()->first();

            return $this->issue($user, $phone, $purpose);
        });

        $gateway = $this->gateways->make();
        $minutes = $this->settings->int('otp_ttl_minutes');
        $label = PhoneOtp::PURPOSE_LABELS[$purpose] ?? '';
        $result = $gateway->sendOtp($phone, '【'.config('app.name')."】{$label}驗證碼 {$code}，{$minutes} 分鐘內有效。請勿提供給他人。");

        // The provider call happens outside the lock. If a newer request superseded this
        // code meanwhile, a late reply must not turn it back into a usable state, so the
        // row is only updated while it is still pending.
        $stillPending = PhoneOtp::query()->whereKey($otp->id)->where('status', PhoneOtp::STATUS_PENDING);

        if (! $result->accepted) {
            $stillPending->update(['status' => PhoneOtp::STATUS_FAILED, 'used_at' => now(), 'provider' => $result->provider]);

            report(new RuntimeException("SMS send failed via {$result->provider}: {$result->errorCode}"));

            throw new ApiException(502, 'SMS_SEND_FAILED', '簡訊發送失敗，請稍後再試。');
        }

        $stillPending->update([
            'status' => PhoneOtp::STATUS_SENT,
            'provider' => $result->provider,
            'provider_message_id' => $result->providerMessageId,
        ]);
    }

    /**
     * Verifies a code for exactly this purpose and returns the consumed code.
     * Only purpose "verify" marks the member's phone as verified; other purposes
     * leave the follow-up action (password reset, phone change) to the caller.
     */
    /**
     * @param  bool  $detailed  false for unauthenticated endpoints: expired and locked codes
     *                          answer OTP_INVALID like unknown numbers, so the error code does
     *                          not reveal that a code (and thus an account) exists.
     * @param  (callable(PhoneOtp): void)|null  $onVerified  runs inside the same transaction
     *                                                       after the code is consumed; if it
     *                                                       fails, the code stays unused.
     */
    public function verify(
        User $user,
        string $code,
        string $purpose = PhoneOtp::PURPOSE_VERIFY,
        ?string $phone = null,
        bool $detailed = true,
        ?callable $onVerified = null,
    ): PhoneOtp {
        $used = null;

        $outcome = DB::transaction(function () use ($user, $code, $purpose, $phone, $onVerified, &$used): string {
            /** @var PhoneOtp|null $otp */
            $otp = PhoneOtp::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->when($phone !== null || $purpose !== PhoneOtp::PURPOSE_PHONE_CHANGE, fn ($query) => $query->where('phone_e164', $phone ?? $user->phone_e164))
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

            if (! hash_equals($otp->code_hash, $this->hash($code, $otp->phone_e164, $purpose))) {
                if ($otp->attempts >= $this->settings->int('otp_max_attempts')) {
                    $otp->forceFill(['status' => PhoneOtp::STATUS_LOCKED, 'used_at' => now()]);
                }

                // Saved (and committed) before the error is returned, so failed attempts always count.
                $otp->save();

                return $otp->status === PhoneOtp::STATUS_LOCKED ? 'locked' : 'invalid';
            }

            $otp->forceFill(['status' => PhoneOtp::STATUS_USED, 'used_at' => now()])->save();

            if ($purpose === PhoneOtp::PURPOSE_VERIFY) {
                $user->forceFill([\n                    'phone_verified_at' => now(),\n                    'membership_verified_at' => $user->membership_verified_at ?? ($this->settings->string('verification_policy') === Settings::POLICY_PHONE ? now() : null),\n                ])->save();
            }

            if ($onVerified !== null) {
                $onVerified($otp);
            }

            $used = $otp;

            return 'ok';
        });

        match (true) {
            $outcome === 'ok' => null,
            ! $detailed => throw self::invalidCode(),
            $outcome === 'expired' => throw new ApiException(422, 'OTP_EXPIRED', '驗證碼已過期，請重新取得。'),
            $outcome === 'locked' => throw new ApiException(422, 'OTP_LOCKED', '錯誤次數過多，請重新取得驗證碼。'),
            default => throw self::invalidCode(),
        };

        return $used;
    }

    /**
     * The generic error for a wrong, unknown, expired or locked code (public endpoints).
     */
    public static function invalidCode(): ApiException
    {
        return new ApiException(422, 'OTP_INVALID', '驗證碼錯誤或已失效，請重新取得。');
    }

    /**
     * For numbers that will not receive a code (unknown, or already verified): the same
     * per-number cooldown / hourly answers as send(), and the same global-limit answer,
     * so the response does not reveal whether an account exists. Nothing is sent.
     */
    public function throttleWithoutSending(string $phone, string $purpose): void
    {
        $this->throttlePerNumber($phone);

        $globalCount = PhoneOtp::query()
            ->whereIn('status', PhoneOtp::COUNTED_STATUSES)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($globalCount >= $this->settings->int('sms_global_hourly_limit')) {
            throw new ApiException(429, 'SMS_GLOBAL_LIMIT', '系統本小時的簡訊發送量已達上限，請稍後再試。');
        }
    }

    /**
     * Per-number cooldown and hourly limit shared by every send path (all purposes,
     * registered or not). Counted in the cache; the MySQL-locked checks in issue()
     * remain the authority for numbers that really receive an SMS.
     */
    private function throttlePerNumber(string $phone): void
    {
        $key = 'otp-number:'.hash('sha256', $phone);
        $cooldownSeconds = $this->settings->int('sms_resend_cooldown_minutes') * 60;

        if (RateLimiter::tooManyAttempts($key.':cooldown', 1)) {
            $retryAfter = max(1, RateLimiter::availableIn($key.':cooldown'));

            throw new ApiException(429, 'SMS_COOLDOWN', "請於 {$retryAfter} 秒後再重新發送。", [], ['Retry-After' => (string) $retryAfter]);
        }

        if (RateLimiter::tooManyAttempts($key.':hour', $this->settings->int('sms_phone_hourly_limit'))) {
            throw new ApiException(429, 'SMS_PHONE_LIMIT', '此門號本小時的簡訊次數已達上限。');
        }

        RateLimiter::hit($key.':cooldown', $cooldownSeconds);
        RateLimiter::hit($key.':hour', 3600);
    }

    /**
     * Runs under the issue lock.
     *
     * @return array{0: PhoneOtp, 1: string}
     */
    private function issue(User $user, string $phone, string $purpose): array
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

        // A new code invalidates any earlier unused code for this phone and purpose.
        // Cooldown and hourly limits above apply across all purposes.
        PhoneOtp::query()
            ->where('phone_e164', $phone)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update(['status' => PhoneOtp::STATUS_SUPERSEDED, 'used_at' => $now]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $otp = PhoneOtp::query()->create([
            'user_id' => $user->id,
            'phone_e164' => $phone,
            'purpose' => $purpose,
            'code_hash' => $this->hash($code, $phone, $purpose),
            'status' => PhoneOtp::STATUS_PENDING,
            'expires_at' => $now->copy()->addMinutes($this->settings->int('otp_ttl_minutes')),
        ]);

        return [$otp, $code];
    }

    private function hash(string $code, string $phone, string $purpose): string
    {
        return hash_hmac('sha256', $purpose.'|'.$phone.'|'.$code, (string) config('app.key'));
    }
}
