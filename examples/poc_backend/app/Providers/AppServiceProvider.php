<?php

namespace App\Providers;

use App\Services\Sms\FakeSmsGateway;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Read settings once per request / job / test.
        $this->app->scoped(Settings::class);
        $this->app->scoped(FakeSmsGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Per-route limits for the public auth endpoints. A plain `throttle:N,1` shares one
        // counter per IP across all routes, so a normal register → login → verify flow could
        // exhaust the strict limit of an unrelated endpoint.
        foreach ([3, 5, 10, 60] as $perMinute) {
            RateLimiter::for("auth-{$perMinute}", fn (Request $request) => Limit::perMinute($perMinute)
                ->by($request->method().'|'.$request->path().'|'.$request->ip()));
        }

        // Member token lifetime (Filament: 登入期限). This callback runs inside Sanctum's guard
        // *before* last_used_at is refreshed, so the idle check sees the previous activity time.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            if (! $isValid) {
                return false;
            }

            $user = $token->tokenable;

            if ($user === null || ($user->is_active ?? true) === false) {
                return false;
            }

            $settings = $this->app->make(Settings::class);

            if ($settings->bool('member_long_login')) {
                return true;
            }

            $lastActivity = $token->last_used_at ?? $token->created_at;

            if ($lastActivity !== null && $lastActivity->lt(now()->subMinutes($settings->int('member_idle_minutes')))) {
                $token->delete();

                return false;
            }

            return true;
        });
    }
}
