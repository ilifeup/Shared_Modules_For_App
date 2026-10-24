<?php

use App\Services\Sms\Every8dGateway;
use App\Services\Sms\FakeSmsGateway;
use App\Services\Sms\MitakeGateway;
use App\Services\Sms\SmsGatewayFactory;
use App\Services\Sms\SmsgoGateway;
use App\Support\Settings;
use EachCore\SharedCore\SmsProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('sends via Mitake with the documented form fields and parses the reply', function () {
    Http::fake(['smsapi.mitake.com.tw/*' => Http::response("[1]\r\nmsgid=1234567890\r\nstatuscode=1\r\nAccountPoint=98\r\n")]);

    $result = (new MitakeGateway(['username' => 'u', 'password' => 'p', 'endpoint' => 'https://smsapi.mitake.com.tw/api/mtk/SmSend']))
        ->sendOtp('+886912345678', '驗證碼 123456');

    expect($result->accepted)->toBeTrue()->and($result->providerMessageId)->toBe('1234567890');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://smsapi.mitake.com.tw/api/mtk/SmSend?CharsetURL=UTF-8')
        && $request->method() === 'POST'
        && $request['username'] === 'u'
        && $request['dstaddr'] === '0912345678'
        && $request['smbody'] === '驗證碼 123456');
});

it('treats a Mitake error status as rejected', function () {
    Http::fake(['*' => Http::response("[1]\r\nstatuscode=e\r\nError=Account error\r\n")]);

    $result = (new MitakeGateway(['username' => 'u', 'password' => 'p', 'endpoint' => 'https://smsapi.mitake.com.tw/api/mtk/SmSend']))
        ->sendOtp('+886912345678', 'x');

    expect($result->accepted)->toBeFalse()->and($result->errorCode)->toBe('MITAKE_e');
});

it('sends via EVERY8D SendSMS.ashx and parses success and failure replies', function () {
    Http::fakeSequence('new.e8d.tw/*')
        ->push('9610040.00,1,1,0,c496f706-batch')
        ->push('-99,發生不明錯誤');

    $gateway = new Every8dGateway(['uid' => 'u', 'password' => 'p', 'site' => 'new.e8d.tw']);

    $ok = $gateway->sendOtp('+886912345678', '驗證碼 123456');
    expect($ok->accepted)->toBeTrue()->and($ok->providerMessageId)->toBe('c496f706-batch');

    $failed = $gateway->sendOtp('+886912345678', '驗證碼 123456');
    expect($failed->accepted)->toBeFalse()->and($failed->errorCode)->toBe('EVERY8D_-99');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://new.e8d.tw/API21/HTTP/SendSMS.ashx'
        && $request['UID'] === 'u'
        && $request['DEST'] === '0912345678'
        && $request['MSG'] === '驗證碼 123456');
});

it('treats an EVERY8D reply with nothing sent as rejected', function () {
    expect(Every8dGateway::interpret('100.00,0,0,1,batch')->accepted)->toBeFalse();
});

it('sends via SMSGO with POST and JSON replies', function () {
    Http::fakeSequence('www.smsgo.com.tw/*')
        ->push(['result' => ['msgid' => 'A1', 'statuscode' => 0, 'statusstr' => 'OK', 'point' => 10]])
        ->push(['result' => ['msgid' => '-2', 'statuscode' => -2, 'statusstr' => 'auth failed', 'point' => 0]]);

    $gateway = new SmsgoGateway(['username' => 'u', 'password' => 'p', 'endpoint' => 'https://www.smsgo.com.tw/sms_gw/sendsms.aspx']);

    expect($gateway->sendOtp('+886912345678', '驗證碼 123456')->accepted)->toBeTrue();
    expect($gateway->sendOtp('+886912345678', '驗證碼 123456')->errorCode)->toBe('SMSGO_-2');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['dstaddr'] === '0912345678'
        && $request['rtype'] === 'JSON'
        && $request['encoding'] === 'BIG5');
});

it('refuses to send when credentials are missing, without any HTTP call', function (SmsProvider $gateway) {
    $result = $gateway->sendOtp('+886912345678', 'x');

    expect($result->accepted)->toBeFalse()->and($result->errorCode)->toBe('NOT_CONFIGURED');
    Http::assertNothingSent();
})->with([
    'mitake' => fn () => new MitakeGateway(['endpoint' => 'https://smsapi.mitake.com.tw/api/mtk/SmSend']),
    'every8d' => fn () => new Every8dGateway(['site' => 'new.e8d.tw']),
    'smsgo' => fn () => new SmsgoGateway(['endpoint' => 'https://www.smsgo.com.tw/sms_gw/sendsms.aspx']),
]);

it('uses the fake transport in test mode regardless of the selected provider', function () {
    app(Settings::class)->put(['sms_provider' => 'every8d', 'sms_test_mode' => true]);

    expect(app(SmsGatewayFactory::class)->make())->toBeInstanceOf(FakeSmsGateway::class);

    app(Settings::class)->put(['sms_test_mode' => false]);

    expect(app(SmsGatewayFactory::class)->make())->toBeInstanceOf(Every8dGateway::class);
});
