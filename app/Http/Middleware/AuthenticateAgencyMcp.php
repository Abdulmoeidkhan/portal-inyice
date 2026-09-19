<?php

namespace App\Http\Middleware;

use App\Mcp\AgencyAccess;
use App\Mcp\OAuth\Metadata;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

class AuthenticateAgencyMcp
{
    public function handle(Request $request, Closure $next)
    {
        $bearer = $request->bearerToken();
        $user = null;
        if ($bearer && substr_count($bearer, '.') === 2) {
            try {
                $psr = app(ResourceServer::class)->validateAuthenticatedRequest((new PsrHttpFactory)->createRequest($request));
                // Parse claims only after the OAuth library verifies signature,
                // expiry and revocation; also bind tokens to this issuer/resource.
                $claims = (new Parser(new JoseEncoder))->parse($bearer)->claims();
                $client = Client::find($psr->getAttribute('oauth_client_id'));
                if ($claims->get('iss', '') === Metadata::issuer()
                    && in_array(Metadata::resource(), $claims->get('aud', []), true)
                    && $client && ! $client->revoked) {
                    $user = User::find($psr->getAttribute('oauth_user_id'));
                    $user?->withAccessToken(AccessToken::fromPsrRequest($psr));
                }
            } catch (OAuthServerException) {
                // Return the discovery challenge without exposing token details.
            }
        }

        if (! $user) {
            return response()->json(['message' => 'Connect your InYice agency account to continue.'], 401)
                ->header('WWW-Authenticate', 'Bearer resource_metadata="'.Metadata::issuer().'/.well-known/oauth-protected-resource/mcp/agency", scope="mcp:use"');
        }

        $request->setUserResolver(fn () => $user);
        Auth::shouldUse('sanctum');
        Auth::guard('sanctum')->setUser($user);
        AgencyAccess::authorize($user);

        return $next($request);
    }
}
