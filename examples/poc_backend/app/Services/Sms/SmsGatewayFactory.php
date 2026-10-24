<?php

namespace App\Services\Sms;

use App\Support\Settings;
use EachCore\SharedCore\SmsProvider;

/**
 * Picks the adapter selected in Filament (sms_provider). Credentials only come
 * from server-side config/env (config/services.php "sms"), never from the database
 * or the App. Test mode always uses the fake transport.
 */
class SmsGatewayFactory
{
    public function __construct(private readonly Settings $settings) {}

    public function make(): SmsProvider
    {
        if ($this->settings->bool('sms_test_mode')) {
            return app(FakeSmsGateway::class);
        }

        return $this->forProvider($this->settings->string('sms_provider'));
    }

    public function forProvider(string $provider): SmsProvider
    {
        $config = config('services.sms', []);

        return match ($provider) {
            'mitake' => new MitakeGateway($config['mitake'] ?? []),
            'every8d' => new Every8dGateway($config['every8d'] ?? []),
            'smsgo' => new SmsgoGateway($config['smsgo'] ?? []),
            default => app(FakeSmsGateway::class),
        };
    }
}
