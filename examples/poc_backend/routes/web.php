<?php

use App\Http\Controllers\Web\EmailVerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Signed link sent in the verification email (name required by Laravel's VerifyEmail notification).
Route::get('/email/verify/{id}/{hash}', EmailVerificationController::class)
    ->whereNumber('id')
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');
