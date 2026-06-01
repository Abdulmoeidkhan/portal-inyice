<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\TenantManager;

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
            $tenantExists = \App\Models\Tenant::where('id', $tenantId)
                ->where('is_active', true)
                ->exists();

            return $tenantExists ? $tenantId : null;
        }

        // Try to get tenant from subdomain
        // Example: agency1.inyice.localhost -> agency1
        $host = $request->getHost();
        $parts = explode('.', $host);
        
        if (count($parts) > 2 && $parts[0] !== 'www') {
            $subdomain = $parts[0];
            $tenant = \App\Models\Tenant::where('code', $subdomain)->first();
            if ($tenant) {
                return $tenant->id;
            }
        }

        return null;
    }
}
