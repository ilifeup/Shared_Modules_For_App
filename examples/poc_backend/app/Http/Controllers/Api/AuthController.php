<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\MemberResource;
use App\Models\AuditEvent;
use App\Models\PhoneOtp;
use App\Models\User;
use App\Services\PhoneOtpService;
use App\Support\Api\ApiException;
use App\Support\Phone;
use App\Support\Settings;
use App\Support\VerificationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly VerificationPolicy $policy,
        private readonly PhoneOtpService $otp,
    ) {}

    /**
     * GET /api/v1/auth/policy — current member verification policy (public).
     */
    public function policy(): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'verification_policy' => $this->policy->current(),
                'required_fields' => ['name', 'email', 'phone', 'password'],
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/register — name, email, Taiwan mobile and password are all required.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::query()->create([
            'name' => $request->string('name')->toString(),
            'display_name' => mb_substr($request->string('name')->toString(), 0, 80),
            'email' => $request->input('email'),
            'phone_e164' => $request->input('phone_e164'),
            'password' => $request->string('password')->toString(),
            'is_active' => true,
            'verified_status' => 1,
            'FamilyUser' => false,
            ]);
            if (filled($request->input('family_name'))) {
                DB::table('registration_family_info')->insert([
                    'user_id' => $user->id,
                    'family_name' => trim((string) $request->input('family_name')),
                    'relationship' => $request->input('family_relationship'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $user;
        });

        AuditEvent::record('member.registered', $user->id, $user);

        // Email verification is always offered; an SMS is only sent automatically when
        // the policy requires the phone (avoids paid SMS during the email stage).
        $user->sendEmailVerificationNotification();
        $verification = ['email' => 'sent', 'phone' => null];

        if ($this->policy->current() === Settings::POLICY_PHONE) {
            try {
                $this->otp->send($user);
                $verification['phone'] = 'sent';
            } catch (ApiException $e) {
                // The account exists; the member can request a new code later.
                $verification['phone'] = 'send_failed:'.$e->errorCode;
            }
        }

        return new JsonResponse([
            'data' => [
                'user' => new MemberResource($user),
                'verification' => $verification,
            ],
        ], 201);
    }

    /**
     * POST /api/v1/auth/login — identifier is an email or a Taiwan mobile number.
     * Unverified members receive a token, but protected endpoints stay closed until
     * the verification policy is satisfied (see data.user.verification).
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->findByIdentifier(trim($request->string('identifier')->toString()));

        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            AuditEvent::record('member.login', $user?->id, $user, [], 'failure');

            throw new ApiException(401, 'INVALID_CREDENTIALS', '帳號或密碼錯誤。');
        }

        if ($user->FamilyUser) {
            throw new ApiException(403, 'FAMILY_LOGIN_FORBIDDEN', '家庭代管會員不可獨立登入。');
        }

        if (! $user->is_active) {
            throw new ApiException(403, 'ACCOUNT_DISABLED', '此帳號已停用。');
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

        $user = $this->uniqueAccountByContact('email', mb_strtolower(trim((string) $request->input('email'))));

        if ($user !== null && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return new JsonResponse(['data' => ['message' => '若此 Email 尚未驗證，驗證信已寄出。']]);
    }

    /**
     * POST /api/v1/auth/phone/send-otp — same answers (including cooldown and hourly
     * limits) for registered and unregistered numbers.
     */
    public function sendPhoneOtp(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30']]);

        $phone = $this->normalizePhone((string) $request->input('phone'));
        $user = $this->uniqueAccountByContact('phone_e164', $phone);

        if ($user !== null && ! $user->hasVerifiedPhone()) {
            $this->sendQuietly($user, PhoneOtp::PURPOSE_VERIFY);
        } else {
            $this->otp->throttleWithoutSending($phone, PhoneOtp::PURPOSE_VERIFY);
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

        $user = $this->uniqueAccountByContact('phone_e164', $this->normalizePhone((string) $request->input('phone')));

        if ($user === null || $user->hasVerifiedPhone()) {
            throw PhoneOtpService::invalidCode();
        }

        // Public endpoint: expired / locked codes answer like unknown numbers.
        $this->otp->verify($user, (string) $request->input('code'), detailed: false);
        AuditEvent::record('member.phone_verified', $user->id, $user);

        return new JsonResponse(['data' => ['verified' => true]]);
    }

    /**
     * Public send: a provider failure answers like a successful send (and like an
     * unknown number). The failure is reported and audited; the member can retry
     * after the cooldown.
     */
    private function sendQuietly(User $user, string $purpose): void
    {
        try {
            $this->otp->send($user, $purpose);
        } catch (ApiException $e) {
            if ($e->errorCode !== 'SMS_SEND_FAILED') {
                throw $e;
            }

            AuditEvent::record('sms.send_failed', $user->id, $user, ['purpose' => $purpose], 'failure');
        }
    }

    private function findByIdentifier(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return $this->uniqueAccountByContact('email', mb_strtolower($identifier));
        }

        $phone = Phone::normalize($identifier);

        return $phone !== null ? $this->uniqueAccountByContact('phone_e164', $phone) : null;
    }

    /**
     * Never pick an arbitrary account when a contact is shared. Contact fields
     * are mutable and may become non-unique; ambiguous login must fail closed.
     */
    private function uniqueAccountByContact(string $column, string $value): ?User
    {
        $matches = User::query()->where($column, $value)->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function normalizePhone(string $input): string
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
