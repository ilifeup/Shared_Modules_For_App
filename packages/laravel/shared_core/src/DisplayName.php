<?php

declare(strict_types=1);

namespace EachCore\SharedCore;

/** Shared domain validation; authorization and persistence belong to each App. */
final class DisplayName
{
    public static function normalize(string $value): string
    {
        return trim($value);
    }

    public static function isValid(string $value): bool
    {
        $value = self::normalize($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

        return $length >= 1 && $length <= 80;
    }
}
