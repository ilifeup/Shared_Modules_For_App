<?php

declare(strict_types=1);

namespace EachCore\SharedCore;

/**
 * Stateless projection of already-authorized application notice records.
 * Authorization and query-level filtering MUST be performed by the App.
 */
final class NoticeReadService
{
    /** @param array<int, array<string, mixed>> $notices
     *  @return array<int, array<string, mixed>>
     */
    public static function publishedOnly(array $notices, string $nowUtc): array
    {
        $result = [];
        foreach ($notices as $notice) {
            $status = $notice['status'] ?? null;
            $publishedAt = $notice['published_at'] ?? null;
            if (!is_string($status) || ($publishedAt !== null && !is_string($publishedAt))) {
                continue;
            }
            if (!NoticeVisibility::isPublished($status, $publishedAt, $nowUtc)) {
                continue;
            }
            $result[] = [
                'id' => (string) ($notice['id'] ?? ''),
                'title' => (string) ($notice['title'] ?? ''),
                'body' => (string) ($notice['body'] ?? ''),
                'status' => $status,
                'published_at' => $publishedAt,
            ];
        }
        return $result;
    }
}
