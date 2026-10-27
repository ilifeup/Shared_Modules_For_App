<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureMemberVerified;
use App\Support\Api\ApiError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/login');
        $middleware->alias(['member.verified' => EnsureMemberVerified::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiError::register($exceptions);
    })->create();
