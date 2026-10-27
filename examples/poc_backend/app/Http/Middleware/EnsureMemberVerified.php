<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiException;
use App\Support\VerificationPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Full member access only when the current verification policy is satisfied.
 * Members who are not yet verified can still use the verification, logout and
 * account-recovery endpoints (those routes do not use this middleware).
 */
class EnsureMemberVerified
{
    public function __construct(private readonly VerificationPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new ApiException(401, 'UNAUTHENTICATED', '請先登入。');
        }
        if (! $user->is_active) {
            throw new ApiException(403, 'ACCOUNT_DISABLED', '此帳號已停用。');
        }
        if ((int) $user->verified_status !== 2) {
            $missing = $this->policy->missing($user);

            throw new ApiException(
                403,
                'VERIFICATION_REQUIRED',
                '尚未取得已驗證會員資格，請完成會員驗證。',
                ['verification' => [(string) $missing]],
            );
        }

        return $next($request);
    }
}
