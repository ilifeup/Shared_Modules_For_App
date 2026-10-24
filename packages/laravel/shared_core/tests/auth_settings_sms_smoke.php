<?php

declare(strict_types=1);

foreach (['AuthSettings', 'SmsProvider', 'SmsSendResult', 'FakeSmsProvider', 'SmsProviderKind'] as $class) {
    require __DIR__ . '/../src/' . $class . '.php';
}

use EachCore\SharedCore\AuthSettings;
use EachCore\SharedCore\FakeSmsProvider;
use EachCore\SharedCore\SmsProviderKind;

$settings = new AuthSettings();
if ($settings->memberIdleMinutes !== null || $settings->adminIdleMinutes !== 10080) {
    throw new RuntimeException('Session defaults mismatch');
}
if (count(SmsProviderKind::cases()) !== 4) {
    throw new RuntimeException('Provider list mismatch');
}
$result = (new FakeSmsProvider())->sendOtp('+886912345678', 'synthetic otp');
if (!$result->accepted || $result->provider !== 'fake') {
    throw new RuntimeException('Fake provider failed');
}
try {
    new AuthSettings(adminIdleMinutes: 0);
    throw new RuntimeException('Invalid settings accepted');
} catch (InvalidArgumentException $expected) {
}
echo "S1 auth settings and SMS provider contract: PASS (no SMS sent)\n";
