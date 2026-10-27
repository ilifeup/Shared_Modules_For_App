<?php

use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvCatalogController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BookingGroupController;
use App\Http\Controllers\Api\CalendarEventController;
use App\Http\Controllers\Api\CheckinController;
use App\Http\Controllers\Api\ContentDiscoveryController;
use App\Http\Controllers\Api\FamilyMembershipController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\LinkAnalyticsController;
use App\Http\Controllers\Api\MarketingController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\MyCalendarController;
use App\Http\Controllers\Api\NoticeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\VerificationController;
use Illuminate\Support\Facades\Route;

// Prefix /api/v1 is set in bootstrap/app.php (apiPrefix).

// S10: guests and registered-but-unverified users cannot list protected media.
Route::middleware(['auth:sanctum', 'member.verified', 'throttle:auth-60'])->prefix('av')->group(function () {
    Route::get('/tracks', [AvCatalogController::class, 'tracks']);
    Route::get('/videos', [AvCatalogController::class, 'videos']);
});

Route::get('/health', HealthController::class)->name('api.health');
Route::get('/membership-levels', [MembershipController::class, 'index']);
Route::get('/notice-categories', [NoticeController::class, 'categories']);
Route::get('/public/notices', [NoticeController::class, 'publicIndex'])->middleware('throttle:auth-60');
Route::get('/public/notices/{id}', [NoticeController::class, 'publicShow'])->whereNumber('id')->middleware('throttle:auth-60');
// S8 M05 articles: optional login; read permission is checked per article on the server.
Route::middleware('throttle:auth-60')->group(function () {
    Route::get('/articles', [ArticleController::class, 'index']);
    Route::get('/articles/featured', [ArticleController::class, 'featured']);
    Route::get('/articles/{id}', [ArticleController::class, 'show'])->whereNumber('id');
    Route::get('/articles/{id}/cover', [ArticleController::class, 'cover'])->whereNumber('id');
    Route::get('/articles/{id}/likes', [ArticleController::class, 'likes'])->whereNumber('id');
    Route::get('/articles/{id}/comments', [ArticleController::class, 'comments'])->whereNumber('id');
});
Route::get('/article-categories', [ArticleController::class, 'categories']);
Route::get('/article-report-reasons', [ArticleController::class, 'reportReasons']);
// S9 public / optional-login reads (content permission is decided per item on the server).
Route::get('/event-categories', [MyCalendarController::class, 'categories']);
Route::get('/verification-types', [VerificationController::class, 'types']);
Route::middleware('throttle:auth-60')->group(function () {
    Route::get('/tags', [ContentDiscoveryController::class, 'tags']);
    Route::get('/search', [ContentDiscoveryController::class, 'search']);
    Route::get('/content/{type}/{id}/share', [ContentDiscoveryController::class, 'share'])->whereIn('type', ['article', 'event', 'notice'])->whereNumber('id');
    Route::get('/marketing/placements/{code}', [MarketingController::class, 'placement']);
    Route::get('/marketing/campaigns/{id}/image', [MarketingController::class, 'image'])->whereNumber('id');
    Route::post('/links/clicks', [LinkAnalyticsController::class, 'click']);
    Route::post('/links/opens', [LinkAnalyticsController::class, 'open']);
});
Route::get('/public/events', [CalendarEventController::class, 'publicIndex'])->middleware('throttle:auth-60');
Route::get('/public/events/{id}', [CalendarEventController::class, 'publicShow'])->whereNumber('id')->middleware('throttle:auth-60');

// Public: registration, verification and account recovery.
// auth-N limiters count per route and client IP (see AppServiceProvider).
Route::prefix('auth')->group(function () {
    Route::get('/policy', [AuthController::class, 'policy']);
    Route::get('/notices', [AuthController::class, 'notices'])->middleware('throttle:auth-60');
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

    // S6 M08 bookings (own bookings only).
    Route::get('/events/{id}/booking', [BookingController::class, 'show'])->whereNumber('id');
    Route::post('/events/{id}/bookings', [BookingController::class, 'store'])->whereNumber('id');
    Route::get('/me/bookings', [BookingController::class, 'mine']);
    Route::post('/me/bookings/{id}/cancel', [BookingController::class, 'cancel'])->whereNumber('id');

    // S8 M05-B interaction (members with read permission).
    Route::post('/articles/{id}/like', [ArticleController::class, 'like'])->whereNumber('id');
    Route::delete('/articles/{id}/like', [ArticleController::class, 'unlike'])->whereNumber('id');
    Route::post('/articles/{id}/comments', [ArticleController::class, 'storeComment'])->whereNumber('id')->middleware('throttle:auth-10');
    Route::post('/article-comments/{id}/reports', [ArticleController::class, 'report'])->whereNumber('id')->middleware('throttle:auth-10');

    // S4 M04 in-app notifications (own notifications only).
    Route::get('/me/notifications', [NotificationController::class, 'index']);
    Route::get('/me/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/me/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::get('/me/notifications/{id}', [NotificationController::class, 'show'])->whereUuid('id');
    Route::post('/me/notifications/{id}/read', [NotificationController::class, 'markRead'])->whereUuid('id');

    // Family delegation: current manager may list or voluntarily revoke authority.
    Route::get('/me/family-memberships', [FamilyMembershipController::class, 'index']);
    Route::post('/me/family-memberships/{id}/revoke', [FamilyMembershipController::class, 'revoke'])->whereNumber('id')->middleware('throttle:auth-10');

    // S9 M08 members-only group bookings and waitlist (one seat engine with S6).
    Route::post('/events/{id}/booking-groups', [BookingGroupController::class, 'store'])->whereNumber('id')->middleware('throttle:auth-60');
    Route::get('/me/booking-groups', [BookingGroupController::class, 'mine']);
    Route::post('/me/booking-groups/{id}/cancel', [BookingGroupController::class, 'cancel'])->whereNumber('id');

    // S9 M09 my calendar, VIP private entries, Google Calendar (one-way).
    Route::get('/me/calendar', [MyCalendarController::class, 'index']);
    Route::post('/me/private-events', [MyCalendarController::class, 'store']);
    Route::patch('/me/private-events/{id}', [MyCalendarController::class, 'update'])->whereNumber('id');
    Route::delete('/me/private-events/{id}', [MyCalendarController::class, 'destroy'])->whereNumber('id');
    Route::get('/me/google-calendar', [MyCalendarController::class, 'googleStatus']);
    Route::post('/me/google-calendar/connect', [MyCalendarController::class, 'googleConnect'])->middleware('throttle:auth-10');
    Route::delete('/me/google-calendar', [MyCalendarController::class, 'googleDisconnect']);

    // S9 M11 check-in (online only).
    Route::get('/events/{id}/checkin', [CheckinController::class, 'show'])->whereNumber('id');
    Route::post('/events/{id}/checkin', [CheckinController::class, 'checkIn'])->whereNumber('id')->middleware('throttle:auth-60');
    Route::post('/events/{id}/checkout', [CheckinController::class, 'checkOut'])->whereNumber('id')->middleware('throttle:auth-60');
    Route::get('/events/{id}/checkin/pass', [CheckinController::class, 'pass'])->whereNumber('id');
    Route::get('/checkin/locations/{token}', [CheckinController::class, 'location'])->where('token', '[A-Za-z0-9]{16,40}');
    Route::get('/events/{id}/checkin/qr', [CheckinController::class, 'qr'])->whereNumber('id');
    Route::post('/events/{id}/checkin/staff', [CheckinController::class, 'staff'])->whereNumber('id')->middleware('throttle:auth-60');
    Route::get('/events/{id}/attendance', [CheckinController::class, 'attendance'])->whereNumber('id');

    // S9 M19 bookmarks (own only).
    Route::get('/me/bookmarks', [ContentDiscoveryController::class, 'bookmarks']);
    Route::post('/me/bookmarks', [ContentDiscoveryController::class, 'addBookmark'])->middleware('throttle:auth-60');
    Route::delete('/me/bookmarks', [ContentDiscoveryController::class, 'clearBookmarks']);
    Route::delete('/me/bookmarks/{type}/{id}', [ContentDiscoveryController::class, 'removeBookmark'])->whereIn('type', ['article', 'event', 'notice'])->whereNumber('id');

    // S9 M03 identity verification (member side).
    Route::get('/me/verifications', [VerificationController::class, 'mine']);
    Route::patch('/me/verifications/{id}', [VerificationController::class, 'updateBadge'])->whereNumber('id');
    Route::post('/me/verification-requests', [VerificationController::class, 'apply'])->middleware('throttle:auth-10');
    Route::post('/me/verification-requests/{id}/withdraw', [VerificationController::class, 'withdraw'])->whereNumber('id');
    Route::get('/me/verification-evidence/{id}', [VerificationController::class, 'evidence'])->whereNumber('id');
});
