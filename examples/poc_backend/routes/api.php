<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarEventController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\NoticeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasswordResetController;
use Illuminate\Support\Facades\Route;

// Prefix /api/v1 is set in bootstrap/app.php (apiPrefix).

Route::get('/health', HealthController::class)->name('api.health');
Route::get('/membership-levels', [MembershipController::class, 'index']);
Route::get('/notice-categories', [NoticeController::class, 'categories']);
Route::get('/public/notices', [NoticeController::class, 'publicIndex'])->middleware('throttle:auth-60');
Route::get('/public/notices/{id}', [NoticeController::class, 'publicShow'])->whereNumber('id')->middleware('throttle:auth-60');
Route::get('/public/events', [CalendarEventController::class, 'publicIndex'])->middleware('throttle:auth-60');
Route::get('/public/events/{id}', [CalendarEventController::class, 'publicShow'])->whereNumber('id')->middleware('throttle:auth-60');

// Public: registration, verification and account recovery.
// auth-N limiters count per route and client IP (see AppServiceProvider).
Route::prefix('auth')->group(function () {
    Route::get('/policy', [AuthController::class, 'policy']);
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth-10');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-10');
    Route::post('/email/resend', [AuthController::class, 'resendEmail'])->middleware('throttle:auth-3');
    Route::post('/phone/send-otp', [AuthController::class, 'sendPhoneOtp'])->middleware('throttle:auth-5');
    Route::post('/phone/verify', [AuthController::class, 'verifyPhone'])->middleware('throttle:auth-10');

    Route::post('/password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:auth-3');
    Route::post('/password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:auth-10');
    Route::post('/password/phone/send-otp', [PasswordResetController::class, 'sendPhoneOtp'])->middleware('throttle:auth-5');
    Route::post('/password/phone/reset', [PasswordResetController::class, 'resetByPhone'])->middleware('throttle:auth-10');

    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

// Logged in, verification not required yet: lets the member see what is missing and
// add or correct the contact that must be verified (old members may lack one).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [MeController::class, 'show']);
    Route::get('/me/profile', [MeController::class, 'show']);
    Route::post('/me/email', [MeController::class, 'changeEmail'])->middleware('throttle:auth-5');
    Route::post('/me/phone/send-otp', [MeController::class, 'sendPhoneChangeCode'])->middleware('throttle:auth-5');
    Route::post('/me/phone/verify', [MeController::class, 'confirmPhoneChange'])->middleware('throttle:auth-10');
});

// Full member access: the verification policy must be satisfied on every request.
Route::middleware(['auth:sanctum', 'member.verified'])->group(function () {
    Route::patch('/me/profile', [MeController::class, 'update']);
    Route::post('/me/avatar', [MeController::class, 'uploadAvatar'])->middleware('throttle:auth-10');
    Route::get('/me/avatar', [MeController::class, 'avatar']);
    Route::delete('/me/avatar', [MeController::class, 'deleteAvatar']);
    Route::get('/me/membership', [MembershipController::class, 'show']);

    Route::get('/notices', [NoticeController::class, 'index']);
    Route::get('/notices/{id}', [NoticeController::class, 'show'])->whereNumber('id');
    Route::post('/notices/{id}/read', [NoticeController::class, 'markRead'])->whereNumber('id');

    // S5 M09 calendar (public and member events).
    Route::get('/events', [CalendarEventController::class, 'index']);
    Route::get('/events/{id}', [CalendarEventController::class, 'show'])->whereNumber('id');

    // S4 M04 in-app notifications (own notifications only).
    Route::get('/me/notifications', [NotificationController::class, 'index']);
    Route::get('/me/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/me/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::get('/me/notifications/{id}', [NotificationController::class, 'show'])->whereUuid('id');
    Route::post('/me/notifications/{id}/read', [NotificationController::class, 'markRead'])->whereUuid('id');
});
