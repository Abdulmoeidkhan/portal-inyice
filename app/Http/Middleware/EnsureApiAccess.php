<?php

namespace App\Http\Middleware;

use App\Auth\PortalSession;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class EnsureApiAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // Session-authenticated requests are portal requests. Bearer credentials
        // must either carry their browser binding or qualify for integrations.
        if ($token instanceof PersonalAccessToken && $token->exists) {
            if (in_array('portal:session', $token->abilities ?? [], true)) {
                abort_unless(PortalSession::matches($request), 401, 'Please sign in again in the portal.');
            } elseif (! $user->isSystemUser()) {
                abort_unless($user->company?->hasIntegrationAccess(), 403,
                    'External API access requires a paid agency plan. Starter users can sign in to the portal.');
            }
        }

        return $next($request);
    }
}
