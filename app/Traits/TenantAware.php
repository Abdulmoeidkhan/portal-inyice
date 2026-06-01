<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use App\Services\TenantManager;

trait TenantAware
{
    /**
     * Boot the TenantAware trait
     */
    public static function bootTenantAware(): void
    {
        // Automatically scope queries to current tenant
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantManager = app(TenantManager::class);
            
            if ($tenantManager->hasTenant()) {
                $builder->where('tenant_id', $tenantManager->getTenantId());
            }
        });
    }

    /**
     * Get the tenant ID for this model
     */
    public function getTenantId(): int
    {
        return $this->getAttribute('tenant_id');
    }
}
