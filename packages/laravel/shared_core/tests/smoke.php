<?php

declare(strict_types=1);

require __DIR__ . '/../src/NoticeVisibility.php';
require __DIR__ . '/../src/DisplayName.php';

use EachCore\SharedCore\DisplayName;
use EachCore\SharedCore\NoticeVisibility;

function check(bool $condition, string $description): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
}

check(NoticeVisibility::isPublished('published', '2026-10-23T08:00:00+00:00', '2026-10-23T09:00:00+00:00'), 'published notice is visible');
check(!NoticeVisibility::isPublished('draft', '2026-10-23T08:00:00+00:00', '2026-10-23T09:00:00+00:00'), 'draft hidden');
check(!NoticeVisibility::isPublished('published', null, '2026-10-23T09:00:00+00:00'), 'missing publication time hidden');
check(!NoticeVisibility::isPublished('published', '2026-10-24T08:00:00+00:00', '2026-10-23T09:00:00+00:00'), 'future publication hidden');
check(!NoticeVisibility::isPublished('published', 'invalid-date', '2026-10-23T09:00:00+00:00'), 'invalid date hidden');
check(DisplayName::normalize('  Test User  ') === 'Test User', 'trim display name');
check(DisplayName::isValid('Test User'), 'valid display name');
check(!DisplayName::isValid('   '), 'blank display name invalid');
check(!DisplayName::isValid(str_repeat('x', 81)), 'overlong display name invalid');

echo "S1 shared PHP domain smoke tests: PASS\n";
