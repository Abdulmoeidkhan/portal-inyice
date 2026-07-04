<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\TenantManager;
use Throwable;

class ResolveTenant
{
    public function __construct(
        private TenantManager $tenantManager
    ) {}

    public function handle(Request $request, Closure $next)
    {
        // Resolve tenant from subdomain, header, or authenticated user
        $tenantId = $this->resolveTenant($request);
        
        if ($tenantId) {
            $this->tenantManager->setTenant($tenantId);
        }

        return $next($request);
    }

    private function resolveTenant(Request $request): ?int
    {
        // Try to get tenant from authenticated user
        if ($request->user()) {
            return (int) $request->user()->tenant_id;
        }

        // Try to get tenant from header for unauthenticated requests
        if ($request->hasHeader('X-Tenant-ID')) {
            $tenantId = (int) $request->header('X-Tenant-ID');
            try {
                $tenantExists = \App\Models\Tenant::where('id', $tenantId)
                    ->where('is_active', true)
                    ->exists();
            } catch (Throwable) {
                return null;
            }

            return $tenantExists ? $tenantId : null;
        }

        // Try to get tenant from subdomain
        // Example: agency1.inyice.localhost -> agency1
        $host = $this->normalizeHost($request->getHost());
        $appDomain = $this->normalizeHost((string) env('APP_DOMAIN', ''));
        $wwwDomain = $this->normalizeHost((string) env('APP_WWW_DOMAIN', ''));

        if ($host === null || in_array($host, array_filter([$appDomain, $wwwDomain]), true)) {
            return null;
        }

        if ($appDomain && str_ends_with($host, '.'.$appDomain)) {
            $subdomain = substr($host, 0, -strlen('.'.$appDomain));

            return $this->tenantIdForSubdomain($subdomain);
        }

        $parts = explode('.', $host);
        
        if (count($parts) > 2 && $parts[0] !== 'www') {
            return $this->tenantIdForSubdomain($parts[0]);
        }

        return null;
    }

    private function tenantIdForSubdomain(string $subdomain): ?int
    {
        if ($subdomain === '' || str_contains($subdomain, '.')) {
            return null;
        }

        try {
            return \App\Models\Tenant::where('code', $subdomain)->value('id');
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeHost(?string $host): ?string
    {
        $host = strtolower(trim((string) $host));

        if ($host === '') {
            return null;
        }

        if (str_contains($host, '://')) {
            $host = (string) parse_url($host, PHP_URL_HOST);
        }

        return preg_replace('/:\d+$/', '', $host) ?: null;
    }
}
