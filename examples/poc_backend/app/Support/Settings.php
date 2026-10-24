<?php

namespace App\Support;

use App\Models\AppSetting;
use EachCore\SharedCore\AuthSettings;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * Admin-editable settings stored in app_settings and enforced by the backend.
 *
 * Registered as a scoped singleton: values are read once per request/job.
 */
final class Settings
{
    /** Defaults define both the available keys and their types. */
    public const DEFAULTS = [
        // Tab 1: login lifetime
        'member_long_login' => true,          // true = member tokens never expire from inactivity
        'member_idle_minutes' => 43200,       // used only when member_long_login is false (30 days)
        'admin_idle_minutes' => 10080,        // Filament: log out after 7 days without activity

        // 會員驗證設定 (S3 A02): which verified contact grants full member access.
        'verification_policy' => self::POLICY_EMAIL,

        // Tab 3: SMS OTP
        'sms_provider' => 'fake',             // fake | mitake | every8d | smsgo
        'sms_test_mode' => true,              // true = never call a paid provider
        'sms_global_hourly_limit' => 100,
        'sms_phone_hourly_limit' => 3,
        'sms_resend_cooldown_minutes' => 3,
        'otp_ttl_minutes' => 5,
        'otp_max_attempts' => 5,
    ];

    public const SMS_PROVIDERS = ['fake', 'mitake', 'every8d', 'smsgo'];

    /** Testing stage: email must be verified; phone OTP optional. */
    public const POLICY_EMAIL = 'email_required';

    /** Store release: phone OTP must be verified; email can be verified later. */
    public const POLICY_PHONE = 'phone_required';

    public const POLICIES = [self::POLICY_EMAIL, self::POLICY_PHONE];

    /** @var array<string, bool|int|string>|null */
    private ?array $values = null;

    /**
     * @return array<string, bool|int|string>
     */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $values = self::DEFAULTS;

        try {
            $stored = AppSetting::query()->whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key');
        } catch (QueryException) {
            // Table not migrated yet (e.g. during install): fall back to defaults.
            return $values;
        }

        foreach ($stored as $key => $raw) {
            $values[$key] = $this->cast($key, json_decode((string) $raw, true));
        }

        return $this->values = $values;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function string(string $key): string
    {
        return (string) $this->get($key);
    }

    public function get(string $key): bool|int|string
    {
        $this->assertKnown($key);

        return $this->all()[$key];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function put(array $values): void
    {
        $merged = $this->all();

        foreach ($values as $key => $value) {
            $this->assertKnown($key);
            $merged[$key] = $this->cast($key, $value);
        }

        // Validate everything before writing anything.
        if (! in_array($merged['sms_provider'], self::SMS_PROVIDERS, true)) {
            throw new InvalidArgumentException("Unknown SMS provider [{$merged['sms_provider']}].");
        }

        if (! in_array($merged['verification_policy'], self::POLICIES, true)) {
            throw new InvalidArgumentException("Unknown verification policy [{$merged['verification_policy']}].");
        }

        // Shared validation of the session/SMS limits (packages/laravel/shared_core).
        new AuthSettings(
            memberIdleMinutes: $merged['member_long_login'] ? null : (int) $merged['member_idle_minutes'],
            adminIdleMinutes: (int) $merged['admin_idle_minutes'],
            smsGlobalHourlyLimit: (int) $merged['sms_global_hourly_limit'],
            smsPhoneHourlyLimit: (int) $merged['sms_phone_hourly_limit'],
            smsResendCooldownMinutes: (int) $merged['sms_resend_cooldown_minutes'],
            otpLifetimeMinutes: (int) $merged['otp_ttl_minutes'],
            otpMaxAttempts: (int) $merged['otp_max_attempts'],
        );

        foreach (array_keys($values) as $key) {
            AppSetting::query()->updateOrCreate(['key' => $key], ['value' => json_encode($merged[$key])]);
        }

        $this->values = null;
    }

    private function cast(string $key, mixed $value): bool|int|string
    {
        $default = self::DEFAULTS[$key];

        return match (true) {
            is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOL),
            is_int($default) => is_numeric($value) ? (int) $value : $default,
            default => is_scalar($value) ? (string) $value : $default,
        };
    }

    private function assertKnown(string $key): void
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new InvalidArgumentException("Unknown setting [{$key}].");
        }
    }
}
