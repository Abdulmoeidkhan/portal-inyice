<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireSystemRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if (!$user->role?->is_system || ($roles && !$user->hasAnyRole($roles))) {
            return response()->json([
                'error' => 'Insufficient permissions for this action',
            ], 403);
        }

        return $next($request);
    }
}
