<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiException;
use App\Support\VerificationPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Full member access only when the persisted users.verified_status is 2.
 * Members who are not yet verified can still use the verification, logout and
 * account-recovery endpoints (those routes do not use this middleware).
 */
class EnsureMemberVerified
{
    public function __construct(private readonly VerificationPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && (int) $user->verified_status !== 2) {
            $missing = $this->policy->missing($user);

            throw new ApiException(
                403,
                'VERIFICATION_REQUIRED',
                '會員資格尚未驗證，請完成目前設定的驗證程序。',
                ['verification' => [(string) $missing]],
            );
        }

        return $next($request);
    }
}
