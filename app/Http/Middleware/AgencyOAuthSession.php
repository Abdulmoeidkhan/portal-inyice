<?php

namespace App\Http\Middleware;

use App\Mcp\AgencyAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AgencyOAuthSession
{
    public function handle(Request $request, Closure $next)
    {
        $guard = Auth::guard('mcp_browser');
        $user = $guard->user();
        if (! $user || ! hash_equals((string) $user->getAuthPassword(), (string) $request->session()->get('mcp_password_hash', ''))) {
            $guard->logout();
            $request->session()->forget(['authToken', 'authRequest', 'mcp_authorizing_user']);
            if ($request->isMethod('GET')) {
                $request->session()->put('mcp_intended', '/'.$request->path().($request->getQueryString() ? '?'.$request->getQueryString() : ''));
            }

            return redirect()->route('mcp.login');
        }
        abort_unless(AgencyAccess::eligible($user), 403, 'An active, verified agency owner, admin or accounts user on a paid plan is required. Starter plans do not include API or MCP access.');
        Auth::shouldUse('mcp_browser');
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
