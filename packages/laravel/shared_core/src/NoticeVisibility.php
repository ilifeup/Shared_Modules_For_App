<?php

declare(strict_types=1);

namespace EachCore\SharedCore;

/**
 * Stateless publication policy shared by API and Filament adapters.
 * Caller must additionally enforce tenant/app-local authorization.
 */
final class NoticeVisibility
{
    public static function isPublished(string $status, ?string $publishedAt, string $nowUtc): bool
    {
        if ($status !== 'published' || $publishedAt === null) {
            return false;
        }

        try {
            $published = new \DateTimeImmutable($publishedAt);
            $now = new \DateTimeImmutable($nowUtc);
        } catch (\Exception $exception) {
            return false;
        }

        return $published <= $now;
    }

    /**
     * S3 M06: published, start reached and (optional) end not yet reached.
     * A withdrawn or draft notice is never visible.
     */
    public static function isVisible(string $status, ?string $publishedAt, ?string $endsAt, string $nowUtc): bool
    {
        if (!self::isPublished($status, $publishedAt, $nowUtc)) {
            return false;
        }

        if ($endsAt === null) {
            return true;
        }

        try {
            return new \DateTimeImmutable($endsAt) > new \DateTimeImmutable($nowUtc);
        } catch (\Exception $exception) {
            return false;
        }
    }
}
