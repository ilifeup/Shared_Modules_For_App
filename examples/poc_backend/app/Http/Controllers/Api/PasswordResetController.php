<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PasswordResetService;
use App\Support\Api\ApiException;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $resets) {}

    /**
     * POST /api/v1/auth/password/forgot — never reveals whether the email is registered.
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        $this->resets->requestByEmail((string) $request->input('email'));

        return new JsonResponse(['data' => ['message' => '若此 Email 已註冊，重設密碼連結已寄出。']]);
    }

    /**
     * POST /api/v1/auth/password/reset — token from the reset email.
     */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $this->resets->resetByEmail((string) $request->input('email'), (string) $request->input('token'), (string) $request->input('password'));

        return new JsonResponse(['data' => ['reset' => true]]);
    }

    /**
     * POST /api/v1/auth/password/phone/send-otp — same answers for registered and unregistered numbers.
     */
    public function sendPhoneOtp(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30']]);

        $this->resets->requestByPhone($this->phone((string) $request->input('phone')));

        return new JsonResponse(['data' => ['message' => '若此手機已註冊，密碼重設驗證碼已發送。']]);
    }

    /**
     * POST /api/v1/auth/password/phone/reset
     */
    public function resetByPhone(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $this->resets->resetByPhone($this->phone((string) $request->input('phone')), (string) $request->input('code'), (string) $request->input('password'));

        return new JsonResponse(['data' => ['reset' => true]]);
    }

    private function phone(string $input): string
    {
        $phone = Phone::normalize($input);

        if ($phone === null) {
            throw new ApiException(422, 'VALIDATION_FAILED', '請輸入台灣手機號碼，例如 0912345678。', [
                'phone' => ['請輸入台灣手機號碼，例如 0912345678。'],
            ]);
        }

        return $phone;
    }
}
