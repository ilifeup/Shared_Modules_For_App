<?php

declare(strict_types=1);

namespace EachCore\SharedCore;

/**
 * Disposable CI provider. Never dispatches a network request or logs OTP text.
 */
final class FakeSmsProvider implements SmsProvider
{
    public function sendOtp(string $phoneE164, string $message): SmsSendResult
    {
        if ($phoneE164 === '' || $message === '') {
            throw new \InvalidArgumentException('Phone and message are required');
        }

        return new SmsSendResult(true, 'fake', 'synthetic-message');
    }
}
