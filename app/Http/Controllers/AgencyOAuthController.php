<?php

namespace App\Http\Controllers;

use App\Mcp\AgencyAccess;
use App\Mcp\OAuth\Metadata;
use App\Mcp\OAuth\RevokeConnections;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use Laravel\Passport\Token;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

class AgencyOAuthController extends Controller
{
    public function loginForm()
    {
        return view('mcp.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:1024']);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password ?? '') || ! AgencyAccess::eligible($user)) {
            return back()->withErrors(['email' => 'Unable to connect. Use a verified owner, admin or accounts login for an active paid agency. Starter plans do not include API or MCP access.'])->onlyInput('email');
        }

        Auth::guard('mcp_browser')->login($user);
        $request->session()->regenerate();
        $request->session()->forget(['authToken', 'authRequest', 'mcp_authorizing_user']);
        $request->session()->put('mcp_password_hash', $user->getAuthPassword());
        $intended = $request->session()->pull('mcp_intended', '/connect/chatgpt');

        return redirect(str_starts_with($intended, '/oauth/authorize?') ? $intended : '/connect/chatgpt');
    }

    public function authorize(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'response_type' => 'required|in:code', 'client_id' => 'required|string',
            'redirect_uri' => 'required|url', 'code_challenge' => 'required|regex:/^[A-Za-z0-9_-]{43}$/',
            'code_challenge_method' => 'required|in:S256',
            'resource' => ['required', 'string', Rule::in([Metadata::resource()])],
            'scope' => 'sometimes|in:mcp:use', 'state' => 'required|string|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'invalid_request', 'error_description' => $validator->errors()->first()], 400);
        }

        // Show the agency and read permissions on every new linking request.
        $request->merge(['prompt' => 'consent', 'scope' => 'mcp:use']);
        $request->session()->put('mcp_authorizing_user', $request->user()->id);
        $request->session()->put('mcp_authorization_url', '/oauth/authorize?'.http_build_query($request->query()));

        return app()->call([app(AuthorizationController::class), 'authorize'], [
            'psrRequest' => (new PsrHttpFactory)->createRequest($request), 'request' => $request,
        ]);
    }

    public function approve(Request $request)
    {
        $this->checkConsentUser($request);

        return app()->call([app(ApproveAuthorizationController::class), 'approve'], ['request' => $request]);
    }

    public function deny(Request $request)
    {
        $this->checkConsentUser($request);

        return app()->call([app(DenyAuthorizationController::class), 'deny'], ['request' => $request]);
    }

    private function checkConsentUser(Request $request): void
    {
        abort_unless((int) $request->session()->pull('mcp_authorizing_user') === $request->user()->id, 403, 'Start the connection again.');
        $request->session()->forget('mcp_authorization_url');
    }

    public function token(Request $request)
    {
        if (! in_array($request->input('grant_type'), ['authorization_code', 'refresh_token'], true)) {
            return response()->json(['error' => 'unsupported_grant_type'], 400);
        }
        // This authorization server serves only this resource, including refreshes.
        if ($request->has('resource') && $request->input('resource') !== Metadata::resource()) {
            return response()->json(['error' => 'invalid_target'], 400);
        }

        return app()->call([app(AccessTokenController::class), 'issueToken'], [
            'request' => (new PsrHttpFactory)->createRequest($request),
        ]);
    }

    public function connections(Request $request)
    {
        $connections = Token::with('client')->where('user_id', $request->user()->id)
            ->where('revoked', false)->whereHas('client', fn ($query) => $query->where('revoked', false))
            ->where(fn ($query) => $query->where('expires_at', '>', now())
                ->orWhereHas('refreshToken', fn ($refresh) => $refresh->where('revoked', false)->where('expires_at', '>', now())))
            ->orderByDesc('created_at')->get()->unique('client_id');

        return view('mcp.connections', ['connections' => $connections, 'user' => $request->user(), 'mcpUrl' => Metadata::resource()]);
    }

    public function disconnect(Request $request, string $clientId, RevokeConnections $revoke)
    {
        $revoke->forUser($request->user()->id, $clientId);

        return redirect()->route('mcp.connections')->with('status', 'Connection removed. That application can no longer read your agency data.');
    }

    public function logout(Request $request)
    {
        $authorizationUrl = $request->session()->get('mcp_authorization_url');
        Auth::guard('mcp_browser')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($authorizationUrl) {
            $request->session()->put('mcp_intended', $authorizationUrl);
        }

        return redirect()->route('mcp.login');
    }
}
