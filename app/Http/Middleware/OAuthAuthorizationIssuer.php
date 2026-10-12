<?php

namespace App\Http\Middleware;

use App\Mcp\OAuth\Metadata;
use Closure;
use Illuminate\Http\Request;

class OAuthAuthorizationIssuer
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $location = $response->headers->get('Location');
        if (! $response->isRedirection() || ! $location) {
            return $response;
        }

        $query = parse_url($location, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $parameters);
        if (isset($parameters['code']) || isset($parameters['error'])) {
            $parameters['iss'] = Metadata::issuer();
            [$target, $fragment] = array_pad(explode('#', $location, 2), 2, null);
            $target = explode('?', $target, 2)[0].'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
            $response->headers->set('Location', $target.($fragment !== null ? '#'.$fragment : ''));
        }

        return $response;
    }
}
