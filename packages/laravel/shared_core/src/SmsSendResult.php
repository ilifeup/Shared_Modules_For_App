<?php

declare(strict_types=1);

namespace EachCore\SharedCore;

final class SmsSendResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly string $provider,
        public readonly ?string $providerMessageId = null,
    ) {
    }
}
