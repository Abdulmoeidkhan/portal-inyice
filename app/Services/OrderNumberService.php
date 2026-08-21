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
        $today = now();
        $prefix = $today->format('Ymd'); // YYYYMMDD
        $lastOrderNumber = Order::withTrashed()
            ->where('company_id', $companyId)
            ->where('tenant_id', $tenantId)
            ->where('order_number', 'like', "ORD-{$prefix}-%")
            ->orderByDesc('order_number')
            ->value('order_number');

        $sequence = 1;
        if (is_string($lastOrderNumber) && preg_match('/^ORD-' . preg_quote($prefix, '/') . '-(\d+)$/', $lastOrderNumber, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

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
