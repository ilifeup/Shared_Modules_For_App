<?php

declare(strict_types=1);

namespace EachCore\SharedCore;

/**
 * S1-A approved token lifetime contract.
 * Applications must configure Sanctum expiration and revoke the current token
 * on logout. This class does not create, authenticate or revoke tokens.
 */
final class TokenPolicy
{
    public const EXPIRATION_MINUTES = 1440;

    public static function expirationMinutes(): int
    {
        return self::EXPIRATION_MINUTES;
    }
}
