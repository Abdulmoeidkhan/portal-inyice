<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\RequireSystemRole;
use App\Http\Middleware\SecurityHeaders;
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
        $trustedProxies = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))));

        $middleware->trustProxies(
            at: $trustedProxies ?: null,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX
        );

        $middleware->throttleApi(limiter: 'api', redis: false);

        $middleware->append(SecurityHeaders::class);

        // Resolve tenant context for all requests
        $middleware->web(ResolveTenant::class);

        $middleware->alias([
            'active.user' => EnsureUserIsActive::class,
            'role' => RequireRole::class,
            'system-role' => RequireSystemRole::class,
            'security.headers' => SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
