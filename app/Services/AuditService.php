<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public function __construct(
        private TenantManager $tenantManager
    ) {}

    /**
     * Log an action
     */
    public function log(
        string $action,
        string $modelType,
        ?int $modelId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?int $userId = null
    ): AuditLog {
        $userId ??= auth()->id();
        $tenantId = $this->tenantManager->assertTenant();

        return AuditLog::create([
            'tenant_id' => $tenantId,
            'uid' => \Illuminate\Support\Str::ulid(),
            'user_id' => $userId,
            'action' => $action,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'description' => $description,
            'created_at' => now(),
        ]);
    }

    /**
     * Log user sign-in
     */
    public function logSignIn(int $userId, int $tenantId): void
    {
        $this->tenantManager->setTenant($tenantId);

        $this->log(
            action: 'user_sign_in',
            modelType: 'User',
            modelId: $userId,
            description: 'User signed in',
            userId: $userId
        );
    }

    /**
     * Log order creation
     */
    public function logOrderCreated(int $orderId, array $orderData, int $userId): void
    {
        $this->log(
            action: 'order_created',
            modelType: 'Order',
            modelId: $orderId,
            newValues: $orderData,
            description: 'Order created',
            userId: $userId
        );
    }

    /**
     * Log order status transition
     */
    public function logOrderStatusChanged(int $orderId, string $fromStatus, string $toStatus, int $userId): void
    {
        $this->log(
            action: 'order_status_changed',
            modelType: 'Order',
            modelId: $orderId,
            oldValues: ['status' => $fromStatus],
            newValues: ['status' => $toStatus],
            description: "Order status changed from {$fromStatus} to {$toStatus}",
            userId: $userId
        );
    }

    /**
     * Log order update
     */
    public function logOrderUpdated(int $orderId, array $oldValues, array $newValues, int $userId): void
    {
        $this->log(
            action: 'order_updated',
            modelType: 'Order',
            modelId: $orderId,
            oldValues: $oldValues,
            newValues: $newValues,
            description: 'Order updated',
            userId: $userId
        );
    }

    /**
     * Log order deletion
     */
    public function logOrderDeleted(int $orderId, array $deletedData, int $userId): void
    {
        $this->log(
            action: 'order_deleted',
            modelType: 'Order',
            modelId: $orderId,
            oldValues: $deletedData,
            description: 'Order deleted',
            userId: $userId
        );
    }

    /**
     * Get audit logs for a model
     */
    public function getModelAudits(string $modelType, int $modelId): \Illuminate\Database\Eloquent\Collection
    {
        return AuditLog::byModel($modelType, $modelId)
            ->recent()
            ->get();
    }

    /**
     * Get recent audits
     */
    public function getRecent(int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return AuditLog::recent()
            ->limit($limit)
            ->get();
    }
}
