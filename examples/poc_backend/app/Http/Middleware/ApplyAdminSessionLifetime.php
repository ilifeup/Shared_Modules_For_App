<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before StartSession on the Filament panel so the session itself lives as
 * long as the admin idle policy (otherwise Laravel's default 120-minute session
 * lifetime would log the admin out long before 7 days).
 */
class ApplyAdminSessionLifetime
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        config(['session.lifetime' => $this->settings->int('admin_idle_minutes')]);

        return $next($request);
    }
}
