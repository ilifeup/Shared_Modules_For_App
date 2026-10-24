<?php

declare(strict_types=1);

namespace EachCore\SharedCore;

/** Implementations must keep API secrets and outbound requests on the server. */
interface SmsProvider
{
    public function sendOtp(string $phoneE164, string $message): SmsSendResult;
}
