<?php

namespace App\Services\Moderation;

/**
 * Reviews one reported comment. Implementations must treat $comment and the report
 * notes as untrusted data and must throw ModerationUnavailable on any failure
 * (not configured, timeout, quota, invalid answer).
 */
interface CommentModerator
{
    /**
     * @param  list<string>  $reasons  report reason codes (no member data)
     */
    public function review(string $comment, array $reasons): ModerationResult;
}
