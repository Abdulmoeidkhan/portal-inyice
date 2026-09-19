<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;

class RejectAgencyMcpToken
{
    public function handle(Request $request, Closure $next)
    {
        // Existing portal APIs do not enforce token abilities. Never let the
        // restricted AI credential inherit those APIs' user-level write access.
        $token = $request->user()?->currentAccessToken();
        abort_if($token instanceof AccessToken, 403, 'Agency OAuth tokens may only use /mcp/agency.');
        $abilities = $token?->abilities ?? [];
        // Continue rejecting any restricted tokens issued before OAuth replaced
        // the developer-token flow; they must never gain portal write access.
        abort_if(in_array('agency:read', $abilities, true), 403, 'Manual agency MCP tokens are no longer supported.');

        return $next($request);
    }
}
