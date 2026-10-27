<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

        // S8 M05 articles: popularity = valid views + likes × weight; view de-duplication
        // window; distinct member reports that trigger the AI review of a comment.
        'article_like_weight' => 10,
        'article_view_dedupe_minutes' => 30,
        'comment_report_threshold' => 3,

        // S9 content types per module (comma separated; allowlist in CONTENT_TYPES).
        'content_search_types' => 'article,event',      // M19-D02/D12
        'content_bookmark_types' => 'article,event',    // M19-D04/D12
        'content_tag_types' => 'article,event',         // M16-D03
        'content_share_types' => 'article,event,notice', // M14-D02/D23
        'content_analytics_types' => 'article,event,notice,campaign', // M17-D02
        'marketing_target_types' => 'article,event,notice,url',       // M15-D02

        // S9 M09 calendar: M02 level codes counted as "高級 VIP" (D22), default zone (D21).
        'calendar_vip_level_codes' => '',
        'calendar_default_timezone' => 'Asia/Taipei',

        // S9 M11 check-in App defaults (events may override GPS, D26) and retention (D24).
        'checkin_gps_required' => false,
        'checkin_gps_radius_m' => 200,
        'checkin_retention_days' => 365,

        // S9 M17 fixed statistics time zone (D29).
        'analytics_timezone' => 'Asia/Taipei',
    ];

    /** Content types S9 modules can refer to (type + id; never class names). */
    public const CONTENT_TYPES = ['article', 'event', 'notice'];

    /** Allowed values per comma-separated setting. */
    public const CSV_ALLOWED = [
        'content_search_types' => ['article', 'event'],
        'content_bookmark_types' => ['article', 'event'],
        'content_tag_types' => ['article', 'event'],
        'content_share_types' => ['article', 'event', 'notice'],
        'content_analytics_types' => ['article', 'event', 'notice', 'campaign'],
        'marketing_target_types' => ['article', 'event', 'notice', 'url'],
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

        foreach (self::CSV_ALLOWED as $key => $allowed) {
            $unknown = array_diff(self::csv((string) $merged[$key]), $allowed);
            if ($unknown !== []) {
                throw new InvalidArgumentException("Setting [{$key}] has unknown types: ".implode(',', $unknown).'.');
            }
        }

        foreach (['calendar_default_timezone', 'analytics_timezone'] as $key) {
            if (! in_array($merged[$key], timezone_identifiers_list(), true)) {
                throw new InvalidArgumentException("Setting [{$key}] must be an IANA time zone.");
            }
        }

        if (preg_match('/^[a-z0-9_,]*$/', (string) $merged['calendar_vip_level_codes']) !== 1) {
            throw new InvalidArgumentException('Setting [calendar_vip_level_codes] must be comma-separated level codes.');
        }

        foreach (['article_like_weight' => [0, 1000], 'article_view_dedupe_minutes' => [1, 1440], 'comment_report_threshold' => [1, 100],
            'checkin_gps_radius_m' => [10, 5000], 'checkin_retention_days' => [1, 3650]] as $key => [$min, $max]) {
            if ($merged[$key] < $min || $merged[$key] > $max) {
                throw new InvalidArgumentException("Setting [{$key}] must be between {$min} and {$max}.");
            }
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

        // V01-A: snapshot existing verified members BEFORE switching to phone-only.
        // This grant is permanent unless an administrator explicitly revokes it.
        DB::transaction(function () use ($values, $merged): void {
            if (array_key_exists('verification_policy', $values)
                && $this->string('verification_policy') === self::POLICY_EMAIL
                && $merged['verification_policy'] === self::POLICY_PHONE) {
                User::query()->whereNotNull('email_verified_at')
                    ->whereNull('verification_grandfathered_at')
                    ->update(['verification_grandfathered_at' => now()]);
            }

            foreach (array_keys($values) as $key) {
                AppSetting::query()->updateOrCreate(['key' => $key], ['value' => json_encode($merged[$key])]);
            }
        });

        $this->values = null;
    }

    /**
     * "a, b,,c" => ['a', 'b', 'c']
     *
     * @return list<string>
     */
    public static function csv(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), fn (string $v): bool => $v !== '')));
    }

    /**
     * Whether $type is enabled in a comma-separated content-type setting.
     */
    public function allows(string $key, string $type): bool
    {
        return in_array($type, self::csv($this->string($key)), true);
    }

    private function cast(string $key, mixed $value): bool|int|string
    {
        $default = self::DEFAULTS[$key];

        return match (true) {
            is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOL),
            is_int($default) => is_numeric($value) ? (int) $value : $default,
            is_array($value) && array_key_exists($key, self::CSV_ALLOWED) || is_array($value) && $key === 'calendar_vip_level_codes' => implode(',', array_map('strval', $value)),
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
