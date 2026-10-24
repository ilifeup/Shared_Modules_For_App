<?php

declare(strict_types=1);

namespace EachCore\SharedCore;

/**
 * Outcome of one provider send. Adapters return a rejected result (never throw)
 * for provider or network errors so the App can apply its own error handling.
 */
final class SmsSendResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly string $provider,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $errorCode = null,
    ) {
    }

    public static function rejected(string $provider, string $errorCode): self
    {
        return new self(false, $provider, null, $errorCode);
    }
}
