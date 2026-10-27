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

        if ($user !== null && ((int) $user->verified_status !== 2 || ! $user->is_active)) {
            $missing = $this->policy->missing($user);

            throw new ApiException(
                403,
                'VERIFICATION_REQUIRED',
                $missing === 'phone' ? '請先完成手機驗證。' : '請先完成 Email 驗證。',
                ['verification' => [(string) $missing]],
            );
        }

        return $next($request);
    }
}
