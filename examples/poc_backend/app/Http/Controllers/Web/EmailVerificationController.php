<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Settings;
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
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            // Recheck the signed email hash on the locked row. Otherwise an email
            // change between the initial SELECT and this lock can verify a new
            // address using the previous address's still-valid signed link.
            abort_unless($locked->email !== null && hash_equals(sha1($locked->getEmailForVerification()), $hash), 403);

            if ($locked->hasVerifiedEmail()) {
                return false;
            }

            $locked->markEmailAsVerified();
            if (app(Settings::class)->string('verification_policy') === Settings::POLICY_EMAIL
                && $locked->membership_verified_at === null) {
                $locked->forceFill(['membership_verified_at' => now()])->save();
            }

            return true;
        });

        if ($newlyVerified) {
            event(new Verified($user));
        }

        return view('auth.email-verified');
    }
}
