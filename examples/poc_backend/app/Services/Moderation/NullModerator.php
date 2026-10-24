<?php

namespace App\Services\Moderation;

/** Used while no AI service is configured: every review is "unavailable". */
final class NullModerator implements CommentModerator
{
    public function review(string $comment, array $reasons): ModerationResult
    {
        throw new ModerationUnavailable('AI moderation is not configured.');
    }
}
