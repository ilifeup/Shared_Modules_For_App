<?php

namespace App\Support;

/**
 * S1 accepts Taiwan mobile numbers only (all three SMS providers are domestic).
 * Stored format: E.164, e.g. +886912345678.
 */
final class Phone
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $value = preg_replace('/[\s\-().]/', '', trim($input)) ?? '';

        if (preg_match('/^09\d{8}$/', $value) === 1) {
            return '+886'.substr($value, 1);
        }

        if (preg_match('/^\+8869\d{8}$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^8869\d{8}$/', $value) === 1) {
            return '+'.$value;
        }

        return null;
    }

    /**
     * +886912345678 -> 0912345678 (format required by Mitake / EVERY8D / SMSGO domestic sends).
     */
    public static function toTaiwanLocal(string $e164): string
    {
        return '0'.substr($e164, 4);
    }

    public static function mask(string $e164): string
    {
        return substr($e164, 0, 6).'****'.substr($e164, -2);
    }
}
