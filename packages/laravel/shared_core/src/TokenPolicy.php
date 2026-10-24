<?php

declare(strict_types=1);

namespace EachCore\SharedCore;

/**
 * Historical S1-A token lifetime, superseded by ADR-006.
 * Applications must use app-local configurable idle expiration and revoke the current token
 * on logout. This class does not create, authenticate or revoke tokens.
 */
final class TokenPolicy
{
    /** @deprecated Historical only; do not configure member Sanctum expiration with this value. */
    public const EXPIRATION_MINUTES = 1440;

    public static function expirationMinutes(): int
    {
        return self::EXPIRATION_MINUTES;
    }
}
