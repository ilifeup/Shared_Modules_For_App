<?php

declare(strict_types=1);

require __DIR__ . '/../src/NoticeVisibility.php';
require __DIR__ . '/../src/NoticeReadService.php';

use EachCore\SharedCore\NoticeReadService;
use EachCore\SharedCore\NoticeVisibility;

$now = '2026-10-23T09:00:00+00:00';
$records = [
    ['id' => 'a', 'title' => 'Public', 'body' => 'OK', 'status' => 'published', 'published_at' => '2026-10-23T08:00:00+00:00', 'secret' => 'must not leak'],
    ['id' => 'b', 'title' => 'Draft', 'body' => 'Hidden', 'status' => 'draft', 'published_at' => $now],
    ['id' => 'c', 'title' => 'Scheduled', 'body' => 'Hidden', 'status' => 'published', 'published_at' => '2026-10-24T08:00:00+00:00'],
];
$result = NoticeReadService::publishedOnly($records, $now);
if (count($result) !== 1 || $result[0]['id'] !== 'a' || isset($result[0]['secret'])) {
    fwrite(STDERR, "Notice projection smoke failed\n");
    exit(1);
}
$checks = [
    NoticeVisibility::isVisible('published', '2026-10-23T08:00:00+00:00', null, $now),
    NoticeVisibility::isVisible('published', '2026-10-23T08:00:00+00:00', '2026-10-23T10:00:00+00:00', $now),
    !NoticeVisibility::isVisible('published', '2026-10-23T08:00:00+00:00', '2026-10-23T09:00:00+00:00', $now),
    !NoticeVisibility::isVisible('withdrawn', '2026-10-23T08:00:00+00:00', null, $now),
    !NoticeVisibility::isVisible('published', '2026-10-24T08:00:00+00:00', null, $now),
];
if (in_array(false, $checks, true)) {
    fwrite(STDERR, "Notice visibility window smoke failed\n");
    exit(1);
}
echo "Notice projection smoke: PASS\n";
