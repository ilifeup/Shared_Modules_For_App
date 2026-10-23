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
}
