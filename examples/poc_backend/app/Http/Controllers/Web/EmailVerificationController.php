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

        abort_unless($user->email !== null && hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        $newlyVerified = DB::transaction(function () use ($user): bool {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
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
