<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\View\View;

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

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            if (app(Settings::class)->string('verification_policy') === Settings::POLICY_EMAIL
                && $user->membership_verified_at === null) {
                $user->forceFill(['membership_verified_at' => now()])->save();
            }
            event(new Verified($user));
        }

        return view('auth.email-verified');
    }
}
