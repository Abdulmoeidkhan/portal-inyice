<?php

namespace App\Services;

use App\Models\Order;

class OrderNumberService
{
    /**
     * Generate next order number for a company
     * Format: ORD-YYYY-MM-XXXXX (XXXXX = sequence)
     */
    public function generateOrderNumber(int $companyId, int $tenantId): string
    {
        $prefix = now()->format('Ymd'); // YYYYMMDD
        $sequence = Order::where('company_id', $companyId)
            ->where('tenant_id', $tenantId)
            ->whereRaw("DATE_FORMAT(created_at, '%Y%m%d') = ?", [$prefix])
            ->count() + 1;

        return sprintf('ORD-%s-%05d', $prefix, $sequence);
    }

    /**
     * Generate unique booking reference/PNR
     * Format: BK-TENANT-SEQUENCE (6 characters total)
     */
    public function generateBookingReference(int $tenantId): string
    {
        $tenantCode = str_pad($tenantId, 3, '0', STR_PAD_LEFT);
        $sequence = Order::where('tenant_id', $tenantId)
            ->count() + 1;

        $sequenceStr = str_pad($sequence % 999, 3, '0', STR_PAD_LEFT);
        return $tenantCode . $sequenceStr; // 6 character PNR
    }
}
