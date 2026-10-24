<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NoticeController;
use Illuminate\Support\Facades\Route;

// Prefix /api/v1 is set in bootstrap/app.php (apiPrefix).

Route::get('/health', HealthController::class)->name('api.health');

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/email/resend', [AuthController::class, 'resendEmail'])->middleware('throttle:3,1');
    Route::post('/phone/send-otp', [AuthController::class, 'sendPhoneOtp'])->middleware('throttle:5,1');
    Route::post('/phone/verify', [AuthController::class, 'verifyPhone'])->middleware('throttle:10,1');

    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [MeController::class, 'show']);
    Route::get('/me/profile', [MeController::class, 'show']);
    Route::patch('/me/profile', [MeController::class, 'update']);

    Route::get('/notices', [NoticeController::class, 'index']);
    Route::get('/notices/{id}', [NoticeController::class, 'show'])->whereNumber('id');
});
