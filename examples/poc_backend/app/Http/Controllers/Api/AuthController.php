<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\MemberResource;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\PhoneOtpService;
use App\Support\Api\ApiException;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PhoneOtpService $otp,
    ) {}

    /**
     * POST /api/v1/auth/register — open self-registration with email and/or Taiwan mobile.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $email = $request->input('email');
        $phone = $request->input('phone_e164');

        if ($email !== null && ! $this->settings->bool('registration_email_enabled')) {
            throw new ApiException(403, 'EMAIL_REGISTRATION_DISABLED', '目前未開放 Email 註冊。');
        }

        if ($phone !== null && ! $this->settings->bool('registration_phone_enabled')) {
            throw new ApiException(403, 'PHONE_REGISTRATION_DISABLED', '目前未開放手機註冊。');
        }

        $user = DB::transaction(function () use ($request, $email, $phone): User {
            $user = User::query()->create([
                'name' => $request->string('name')->toString(),
                'display_name' => mb_substr($request->string('name')->toString(), 0, 80),
                'email' => $email,
                'phone_e164' => $phone,
                'password' => $request->string('password')->toString(),
                'is_active' => true,
            ]);

            if ($email !== null && ! $this->settings->bool('email_verification_required')) {
                $user->markEmailAsVerified();
            }

            if ($phone !== null && ! $this->settings->bool('phone_verification_required')) {
                $user->forceFill(['phone_verified_at' => now()])->save();
            }

            return $user;
        });

        AuditEvent::record('member.registered', $user->id, $user);

        $verification = ['email' => null, 'phone' => null];

        if ($email !== null) {
            if ($user->hasVerifiedEmail()) {
                $verification['email'] = 'verified';
            } else {
                $user->sendEmailVerificationNotification();
                $verification['email'] = 'sent';
            }
        }

        if ($phone !== null) {
            if ($user->hasVerifiedPhone()) {
                $verification['phone'] = 'verified';
            } else {
                try {
                    $this->otp->send($user);
                    $verification['phone'] = 'sent';
                } catch (ApiException $e) {
                    // The account exists; the member can request a new code later.
                    $verification['phone'] = 'send_failed:'.$e->errorCode;
                }
            }
        }

        return (new JsonResponse([
            'data' => [
                'user' => new MemberResource($user),
                'verification' => $verification,
            ],
        ], 201));
    }

    /**
     * POST /api/v1/auth/login — identifier is an email or a Taiwan mobile number.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $identifier = trim($request->string('identifier')->toString());
        $byEmail = str_contains($identifier, '@');

        $user = $byEmail
            ? User::query()->where('email', mb_strtolower($identifier))->first()
            : (($phone = Phone::normalize($identifier)) !== null
                ? User::query()->where('phone_e164', $phone)->first()
                : null);

        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw new ApiException(401, 'INVALID_CREDENTIALS', '帳號或密碼錯誤。');
        }

        if (! $user->is_active) {
            throw new ApiException(403, 'ACCOUNT_DISABLED', '此帳號已停用。');
        }

        if ($byEmail && ! $user->hasVerifiedEmail() && $this->settings->bool('email_verification_required')) {
            throw new ApiException(403, 'EMAIL_NOT_VERIFIED', '請先完成 Email 驗證。');
        }

        if (! $byEmail && ! $user->hasVerifiedPhone() && $this->settings->bool('phone_verification_required')) {
            throw new ApiException(403, 'PHONE_NOT_VERIFIED', '請先完成手機驗證。');
        }

        $token = $user->createToken($request->input('device_name') ?: 'mobile');
        AuditEvent::record('member.login', $user->id);

        return new JsonResponse([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'user' => new MemberResource($user),
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/logout — revokes only the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();
        AuditEvent::record('member.logout', $request->user()?->id);

        return new JsonResponse(['data' => ['logged_out' => true]]);
    }

    /**
     * POST /api/v1/auth/email/resend — same answer whether or not the email exists.
     */
    public function resendEmail(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        $user = User::query()->where('email', mb_strtolower(trim((string) $request->input('email'))))->first();

        if ($user !== null && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return new JsonResponse(['data' => ['message' => '若此 Email 尚未驗證，驗證信已寄出。']]);
    }

    /**
     * POST /api/v1/auth/phone/send-otp
     */
    public function sendPhoneOtp(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30']]);

        $user = $this->findByPhone((string) $request->input('phone'));

        if ($user !== null && ! $user->hasVerifiedPhone()) {
            $this->otp->send($user);
        }

        return new JsonResponse(['data' => ['message' => '若此手機尚未驗證，驗證碼已發送。']]);
    }

    /**
     * POST /api/v1/auth/phone/verify
     */
    public function verifyPhone(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $user = $this->findByPhone((string) $request->input('phone'));

        if ($user === null || $user->hasVerifiedPhone()) {
            throw new ApiException(422, 'OTP_INVALID', '驗證碼錯誤或已失效。');
        }

        $this->otp->verify($user, (string) $request->input('code'));

        return new JsonResponse(['data' => ['verified' => true]]);
    }

    private function findByPhone(string $input): ?User
    {
        $phone = Phone::normalize($input);

        if ($phone === null) {
            throw new ApiException(422, 'VALIDATION_FAILED', '請輸入台灣手機號碼，例如 0912345678。', [
                'phone' => ['請輸入台灣手機號碼，例如 0912345678。'],
            ]);
        }

        return User::query()->where('phone_e164', $phone)->first();
    }
}
