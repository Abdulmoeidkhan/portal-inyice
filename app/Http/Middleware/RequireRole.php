<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $developerRead = $user->hasRole('dev') && $request->isMethodSafe()
            && count(array_intersect($roles, ['owner', 'admin', 'accounts', 'sales', 'dev'])) > 0
            && ! $request->is('api/v1/internal/*');

        if (!$developerRead && !$user->hasAnyRole($roles)) {
            return response()->json([
                'error' => 'Insufficient permissions for this action',
            ], 403);
        }

        return $next($request);
    }
}
