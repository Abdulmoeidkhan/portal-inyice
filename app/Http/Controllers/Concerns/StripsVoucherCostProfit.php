<?php

namespace App\Http\Controllers\Concerns;

trait StripsVoucherCostProfit
{
    private function stripVoucherCostProfit(array $voucher): array
    {
        foreach (($voucher['pricing'] ?? []) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach (self::PRICING_COST_PROFIT_FIELDS as $field) {
                unset($row[$field]);
            }

            $voucher['pricing'][$index] = $row;
        }

        foreach (['hotels', 'transfers', 'city_tours', 'visa', 'other_services'] as $section) {
            foreach (($voucher[$section] ?? []) as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                foreach (self::SERVICE_COST_PROFIT_FIELDS as $field) {
                    unset($row[$field]);
                }

                $voucher[$section][$index] = $row;
            }
        }

        return $voucher;
    }
}
