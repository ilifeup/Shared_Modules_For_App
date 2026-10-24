<?php

declare(strict_types=1);

namespace EachCore\SharedCore;

use InvalidArgumentException;

/**
 * Validated, app-local settings value object.
 * No persistence, Sanctum configuration or Filament UI is provided here.
 */
final class AuthSettings
{
    public function __construct(
        public readonly ?int $memberIdleMinutes = null,
        public readonly int $adminIdleMinutes = 10080,
        public readonly int $smsGlobalHourlyLimit = 100,
        public readonly int $smsPhoneHourlyLimit = 3,
        public readonly int $smsResendCooldownMinutes = 3,
        public readonly int $otpLifetimeMinutes = 5,
        public readonly int $otpMaxAttempts = 5,
    ) {
        if ($memberIdleMinutes !== null && $memberIdleMinutes < 1) {
            throw new InvalidArgumentException('Member idle limit must be positive or null');
        }
        foreach ([
            'adminIdleMinutes' => $adminIdleMinutes,
            'smsGlobalHourlyLimit' => $smsGlobalHourlyLimit,
            'smsPhoneHourlyLimit' => $smsPhoneHourlyLimit,
            'smsResendCooldownMinutes' => $smsResendCooldownMinutes,
            'otpLifetimeMinutes' => $otpLifetimeMinutes,
            'otpMaxAttempts' => $otpMaxAttempts,
        ] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException($name . ' must be positive');
            }
        }
    }
}
