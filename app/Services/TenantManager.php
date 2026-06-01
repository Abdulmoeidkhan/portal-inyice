<?php

namespace App\Services;

class TenantManager
{
    private ?int $tenantId = null;

    /**
     * Set the current tenant context
     */
    public function setTenant(int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    /**
     * Get the current tenant ID
     */
    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    /**
     * Check if tenant is set
     */
    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    /**
     * Assert tenant is set, throw exception if not
     */
    public function assertTenant(): int
    {
        if (!$this->hasTenant()) {
            throw new \RuntimeException('No tenant context set');
        }
        return $this->tenantId;
    }

    /**
     * Clear tenant context
     */
    public function clear(): void
    {
        $this->tenantId = null;
    }
}
