<?php

namespace App\Services\Moderation;

/**
 * AI review outcome. Only "keep" leaves a reported comment public; "hide" and
 * "unsure" hide it until an admin decides. The AI never deletes.
 */
final class ModerationResult
{
    public const KEEP = 'keep';

    public const HIDE = 'hide';

    public const UNSURE = 'unsure';

    public function __construct(
        public readonly string $decision,
        public readonly string $reason = '',
    ) {}
}
