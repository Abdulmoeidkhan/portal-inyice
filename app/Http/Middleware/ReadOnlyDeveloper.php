<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ReadOnlyDeveloper
{
    public function handle(Request $request, Closure $next)
    {
        $readOnlyToken = in_array('data:read', $request->user()?->currentAccessToken()?->abilities ?? [], true);
        $readOnly = $request->user()?->hasRole('dev') || $readOnlyToken;
        $ownProfileUpdate = ! $readOnlyToken && $request->isMethod('PATCH')
            && $request->routeIs('auth.profile.update');

        if ($readOnly && ! $request->isMethodSafe()
            && ! $request->is('api/v1/auth/logout') && ! $ownProfileUpdate) {
            return response()->json(['error' => 'Developer access is read-only.'], 403);
        }

        return $next($request);
    }
}
