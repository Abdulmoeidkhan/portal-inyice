<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePaidFeature
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        abort_unless($user && ! $user->isSystemUser()
            && in_array($user->role?->code, $roles, true)
            && $user->company?->hasIntegrationAccess(), 403,
            'This feature requires an eligible role and a paid company plan.');

        return $next($request)->header('Cache-Control', 'private, no-store');
    }
}
