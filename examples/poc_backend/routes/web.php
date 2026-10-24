<?php

use App\Http\Controllers\Web\ArticleSharePageController;
use App\Http\Controllers\Web\EmailVerificationController;
use App\Http\Controllers\Web\PasswordResetPageController;
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

// S8 M05 shared article link (read permission enforced; see the controller).
Route::get('/articles/{slug}', ArticleSharePageController::class)
    ->where('slug', '[A-Za-z0-9-]+')
    ->middleware('throttle:60,1')
    ->name('articles.share');
