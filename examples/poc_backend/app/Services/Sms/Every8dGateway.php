<?php

namespace App\Services\Sms;

use App\Support\Phone;
use EachCore\SharedCore\SmsProvider;
use EachCore\SharedCore\SmsSendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * EVERY8D (互動資通) SMS API 2.1 — SendSMS.ashx.
 *
 * Source: 互動資通簡訊 API2.1 規格書 Version 2.2 (2025).
 * - POST https://{SiteUrl}/API21/HTTP/SendSMS.ashx, application/x-www-form-urlencoded
 * - SiteUrl for enterprise users: new.e8d.tw (exclusive platforms get theirs from sales)
 * - Auth: UID + PWD in the body (Bearer token is the alternative; not used here)
 * - Params: SB (subject, optional), MSG, DEST, ST (empty = send now)
 * - Success: "CREDIT,SENDED,COST,UNSEND,BATCHID"; failure: "Status,Msg" (e.g. "-99,...")
 */
final class Every8dGateway implements SmsProvider
{
    /**
     * @param  array{uid?: ?string, password?: ?string, site?: ?string, timeout?: int}  $config
     */
    public function __construct(private readonly array $config) {}

    public function sendOtp(string $phoneE164, string $message): SmsSendResult
    {
        $uid = $this->config['uid'] ?? null;
        $password = $this->config['password'] ?? null;
        $site = $this->config['site'] ?? null;

        if (! $uid || ! $password || ! $site) {
            return SmsSendResult::rejected('every8d', 'NOT_CONFIGURED');
        }

        try {
            $response = Http::asForm()
                ->timeout($this->config['timeout'] ?? 10)
                ->post('https://'.$site.'/API21/HTTP/SendSMS.ashx', [
                    'UID' => $uid,
                    'PWD' => $password,
                    'SB' => '',
                    'MSG' => $message,
                    'DEST' => Phone::toTaiwanLocal($phoneE164),
                    'ST' => '',
                ]);
        } catch (ConnectionException) {
            return SmsSendResult::rejected('every8d', 'CONNECTION_FAILED');
        }

        if (! $response->successful()) {
            return SmsSendResult::rejected('every8d', 'HTTP_'.$response->status());
        }

        return self::interpret($response->body());
    }

    public static function interpret(string $body): SmsSendResult
    {
        $parts = array_map('trim', explode(',', trim($body)));

        // Success: CREDIT,SENDED,COST,UNSEND,BATCHID
        if (count($parts) >= 5 && is_numeric($parts[0]) && (float) $parts[0] >= 0) {
            if ((int) $parts[1] < 1) {
                return SmsSendResult::rejected('every8d', 'EVERY8D_UNSENT');
            }

            return new SmsSendResult(true, 'every8d', $parts[4]);
        }

        // Failure: Status,Msg
        return SmsSendResult::rejected('every8d', 'EVERY8D_'.($parts[0] !== '' ? $parts[0] : 'UNKNOWN'));
    }
}
