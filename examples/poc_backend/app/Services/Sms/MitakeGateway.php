<?php

namespace App\Services\Sms;

use App\Support\Phone;
use EachCore\SharedCore\SmsProvider;
use EachCore\SharedCore\SmsSendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 三竹資訊 Mitake HTTP API (single SMS, SmSend).
 *
 * VERIFICATION STATUS: the official spec (B2C_MitakeAPI_v2.14.pdf, dm.mitake.com.tw)
 * could not be read from the build environment. Endpoint and parameter names
 * (username, password, dstaddr, smbody, CharsetURL=UTF-8) follow published integration
 * guides; the success status codes below MUST be checked against the official PDF
 * before enabling live sending. HTTP API access must first be requested from Mitake.
 *
 * Endpoint differs by account type and is configurable (MITAKE_ENDPOINT):
 * - enterprise: https://smsapi.mitake.com.tw/api/mtk/SmSend
 * - personal:   https://smsb2c.mitake.com.tw/b2c/mtk/SmSend
 */
final class MitakeGateway implements SmsProvider
{
    /** statuscode values treated as accepted (to be confirmed with the official spec). */
    private const ACCEPTED_STATUS = ['0', '1', '2', '4'];

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
            return SmsSendResult::rejected('mitake', 'NOT_CONFIGURED');
        }

        try {
            $response = Http::asForm()
                ->timeout($this->config['timeout'] ?? 10)
                ->post($endpoint.'?CharsetURL=UTF-8', [
                    'username' => $username,
                    'password' => $password,
                    'dstaddr' => Phone::toTaiwanLocal($phoneE164),
                    'smbody' => $message,
                ]);
        } catch (ConnectionException) {
            return SmsSendResult::rejected('mitake', 'CONNECTION_FAILED');
        }

        if (! $response->successful()) {
            return SmsSendResult::rejected('mitake', 'HTTP_'.$response->status());
        }

        $fields = self::parse($response->body());
        $status = $fields['statuscode'] ?? null;

        if ($status !== null && in_array($status, self::ACCEPTED_STATUS, true)) {
            return new SmsSendResult(true, 'mitake', $fields['msgid'] ?? null);
        }

        return SmsSendResult::rejected('mitake', 'MITAKE_'.($status ?? 'UNKNOWN'));
    }

    /**
     * Response is "key=value" lines (optionally preceded by "[1]").
     *
     * @return array<string, string>
     */
    public static function parse(string $body): array
    {
        $fields = [];

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $fields[trim($key)] = trim($value);
            }
        }

        return $fields;
    }
}
