<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filament admin: log out only after admin_idle_minutes (default 7 days) without
 * any request. Every request refreshes the last-activity time (sliding window).
 * Member API tokens are not affected: they are handled by Sanctum (see AppServiceProvider).
 */
class EnforceAdminIdle
{
    public const SESSION_KEY = 'admin_last_activity';

    public function __construct(private readonly Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $last = $session->get(self::SESSION_KEY);
        $limitSeconds = $this->settings->int('admin_idle_minutes') * 60;

        if (is_int($last) && now()->getTimestamp() - $last > $limitSeconds) {
            Filament::auth()->logout();
            $session->invalidate();
            $session->regenerateToken();

            return redirect()->to(Filament::getLoginUrl());
        }

        $session->put(self::SESSION_KEY, now()->getTimestamp());

        return $next($request);
    }
}
