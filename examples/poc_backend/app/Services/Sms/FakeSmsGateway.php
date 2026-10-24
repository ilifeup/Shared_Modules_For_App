<?php

namespace App\Services\Sms;

use App\Support\Phone;
use EachCore\SharedCore\SmsProvider;
use EachCore\SharedCore\SmsSendResult;
use Illuminate\Support\Facades\Log;

/**
 * Test-mode transport: never contacts a provider and never costs money.
 *
 * Outside production the message is written to the application log so a
 * developer can complete phone verification locally. In production nothing
 * is logged.
 */
final class FakeSmsGateway implements SmsProvider
{
    /** @var list<array{phone: string, message: string}> Messages sent in this request/test. */
    public array $sent = [];

    public function sendOtp(string $phoneE164, string $message): SmsSendResult
    {
        $this->sent[] = ['phone' => $phoneE164, 'message' => $message];

        if (! app()->isProduction()) {
            Log::info('[FAKE SMS] '.Phone::mask($phoneE164).' '.$message);
        }

        return new SmsSendResult(true, 'fake', 'fake-'.count($this->sent));
    }
}
