<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRegistrationSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = $request->session()->get('registration_started_at');
        $token = $request->header('X-CSRF-TOKEN');

        // Require the form's session token even when Laravel accepts Fetch Metadata.
        if (!is_int($startedAt) || $startedAt < now()->subHours(2)->timestamp
            || !is_string($token) || !hash_equals($request->session()->token(), $token)) {
            return response()->json([
                'message' => 'Your registration session has expired. Refresh the page and try again.',
            ], 419);
        }

        // Real users leave this off-screen field empty.
        if ($request->filled('website')) {
            return response()->json(['message' => 'Registration could not be submitted.'], 422);
        }

        return $next($request);
    }
}
