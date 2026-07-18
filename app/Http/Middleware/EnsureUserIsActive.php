<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && !$user->is_active) {
            return response()->json([
                'error' => 'Your account is inactive. Contact administrator.',
            ], 403);
        }

        if ($user && !$user->isSystemUser() && $user->company && !$user->company->is_active) {
            return response()->json([
                'error' => 'Your company account is inactive. Contact administrator.',
            ], 403);
        }

        return $next($request);
    }
}
