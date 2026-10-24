<?php

namespace App\Services\Sms;

use App\Support\Phone;
use EachCore\SharedCore\SmsProvider;
use EachCore\SharedCore\SmsSendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 簡訊購 SMSGO — sendsms.aspx.
 *
 * Source: SMSGO API 程式規格書 (www.smsgo.com.tw/api/SMSGO_API.pdf).
 * - POST https://www.smsgo.com.tw/sms_gw/sendsms.aspx, application/x-www-form-urlencoded
 *   (GET is deprecated because credentials end up in server logs)
 * - Params: username, password (password or API key), dstaddr (09xxxxxxxx), smbody,
 *   encoding (default BIG5), rtype=JSON
 * - JSON response: {"result": {"msgid", "statuscode", "statusstr", "point"}}; statuscode 0 = success
 * - The API must first be enabled by SMSGO customer service; IP checks may apply (-2 / -15).
 *
 * NOT VERIFIED: the spec does not state how the request body bytes are decoded for
 * BIG5; this adapter converts the message to BIG-5 bytes. Confirm with SMSGO before going live.
 */
final class SmsgoGateway implements SmsProvider
{
    /**
     * @param  array{username?: ?string, password?: ?string, endpoint?: ?string, timeout?: int}  $config
     */
    public function __construct(private readonly array $config) {}

    public function sendOtp(string $phoneE164, string $message): SmsSendResult
    {
        $username = $this->config['username'] ?? null;
        $password = $this->config['password'] ?? null;
        $endpoint = $this->config['endpoint'] ?? null;

        if (! $username || ! $password || ! $endpoint) {
            return SmsSendResult::rejected('smsgo', 'NOT_CONFIGURED');
        }

        try {
            $response = Http::asForm()
                ->timeout($this->config['timeout'] ?? 10)
                ->post($endpoint, [
                    'username' => $username,
                    'password' => $password,
                    'dstaddr' => Phone::toTaiwanLocal($phoneE164),
                    'smbody' => mb_convert_encoding($message, 'BIG-5', 'UTF-8'),
                    'encoding' => 'BIG5',
                    'rtype' => 'JSON',
                ]);
        } catch (ConnectionException) {
            return SmsSendResult::rejected('smsgo', 'CONNECTION_FAILED');
        }

        if (! $response->successful()) {
            return SmsSendResult::rejected('smsgo', 'HTTP_'.$response->status());
        }

        return self::interpret($response->json());
    }

    public static function interpret(mixed $json): SmsSendResult
    {
        $result = is_array($json) ? ($json['result'] ?? null) : null;

        if (! is_array($result) || ! isset($result['statuscode'])) {
            return SmsSendResult::rejected('smsgo', 'SMSGO_INVALID_RESPONSE');
        }

        if ((string) $result['statuscode'] === '0') {
            return new SmsSendResult(true, 'smsgo', isset($result['msgid']) ? (string) $result['msgid'] : null);
        }

        return SmsSendResult::rejected('smsgo', 'SMSGO_'.$result['statuscode']);
    }
}
