<?php

use App\Http\Controllers\Web\AdminEvidenceController;
use App\Http\Controllers\Web\ContentPageController;
use App\Http\Controllers\Web\EmailVerificationController;
use App\Http\Controllers\Web\PasswordResetPageController;
use App\Http\Controllers\Web\WellKnownController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Signed link sent in the verification email (name required by Laravel's VerifyEmail notification).
Route::get('/email/verify/{id}/{hash}', EmailVerificationController::class)
    ->whereNumber('id')
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

// Password reset link from the email (name required by Laravel's ResetPassword notification).
Route::get('/password/reset/{token}', [PasswordResetPageController::class, 'show'])->name('password.reset');
Route::post('/password/reset', [PasswordResetPageController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('password.update');

// S9 M14 shared content links (fixed ids; S8 slug links keep working). Read permission
// is enforced in the controller; member content never shows its title or metadata.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/articles/{key}', [ContentPageController::class, 'article'])->where('key', '[A-Za-z0-9-]+')->name('articles.share');
    Route::get('/events/{id}', [ContentPageController::class, 'event'])->whereNumber('id')->name('events.share');
    Route::get('/notices/{id}', [ContentPageController::class, 'notice'])->whereNumber('id')->name('notices.share');
    Route::get('/checkin/l/{token}', [WellKnownController::class, 'checkinLocation'])->where('token', '[A-Za-z0-9]{16,40}');
    Route::get('/oauth/google/callback', [WellKnownController::class, 'googleCallback'])->name('google.callback');
});
// S9 M08 guest booking (name + email or phone; no email or SMS is sent).
Route::post('/events/{id}/guest-booking', [ContentPageController::class, 'guestBooking'])->whereNumber('id')->middleware('throttle:10,1');

// S9 M14 App Links / Universal Links association (404 until the real IDs are configured).
Route::get('/.well-known/assetlinks.json', [WellKnownController::class, 'assetLinks']);
Route::get('/.well-known/apple-app-site-association', [WellKnownController::class, 'appleAssociation']);

// S9 M03 evidence for reviewers (admin session + short-lived signed URL).
Route::get('/admin/verification-evidence/{id}', AdminEvidenceController::class)
    ->whereNumber('id')
    ->middleware('signed')
    ->name('admin.verification-evidence');
