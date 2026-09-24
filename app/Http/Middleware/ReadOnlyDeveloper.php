<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ReadOnlyDeveloper
{
    public function handle(Request $request, Closure $next)
    {
        $readOnly = $request->user()?->hasRole('dev')
            || in_array('data:read', $request->user()?->currentAccessToken()?->abilities ?? [], true);

        if ($readOnly && ! $request->isMethodSafe()
            && ! $request->is('api/v1/auth/logout')) {
            return response()->json(['error' => 'Developer access is read-only.'], 403);
        }

        return $next($request);
    }
}
