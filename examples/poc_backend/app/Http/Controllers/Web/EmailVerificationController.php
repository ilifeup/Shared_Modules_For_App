<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\VerificationPolicy;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * Target of the signed link in the verification email (route name verification.verify).
 * The member opens it in a browser, then returns to the App to log in.
 */
class EmailVerificationController extends Controller
{
    public function __invoke(int $id, string $hash): View
    {
        $user = User::query()->findOrFail($id);

        $newlyVerified = DB::transaction(function () use ($user, $hash): bool {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            // Verify against the locked row: a concurrent email change must
            // invalidate the old signed URL before any membership is granted.
            abort_unless($fresh->email !== null
                && hash_equals(sha1($fresh->getEmailForVerification()), $hash), 403);
            $changed = ! $fresh->hasVerifiedEmail();
            if ($changed) {
                $fresh->markEmailAsVerified();
            }
            app(VerificationPolicy::class)->grantIfEligible($fresh);

            return $changed;
        });
        if ($newlyVerified) {
            event(new Verified($user));
        }

        return view('auth.email-verified');
    }
}
