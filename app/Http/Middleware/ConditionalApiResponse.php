<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ConditionalApiResponse
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if ($request->isMethod('GET') && $request->user()
            && $request->is('api/v1/orders', 'api/v1/invoices', 'api/v1/customers', 'api/v1/vendors', 'api/v1/reports/tax')
            && $response->getStatusCode() === 200) {
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-cache');
            $response->setEtag(hash('sha256', $response->getContent()));
            $response->isNotModified($request);
        }
        return $response;
    }
}
