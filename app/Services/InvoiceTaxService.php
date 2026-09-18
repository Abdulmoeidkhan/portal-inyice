<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\RefundAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceTaxService
{
    public const CATEGORIES = [
        'flights' => 'Flights',
        'hotels' => 'Hotels',
        'transfers' => 'Transfers',
        'city_tours' => 'City Tours / Ziarat',
        'visa' => 'Visa',
        'other_services' => 'Other Services',
    ];

    public static function defaults(): array
    {
        return ['enabled' => false, 'name' => 'VAT', 'registration_number' => null,
            'rate' => '0', 'pricing' => 'exclusive', 'categories' => []];
    }

    public function snapshot(Order $order): ?array
    {
        // Refunds always retain the original invoice's treatment, including no tax.
        if (isset($order->meta['refund_of_order_id'])) {
            return $order->meta['invoice_tax_settings'] ?? null;
        }

        $settings = $order->company->tax_settings;
        if (! ($settings['enabled'] ?? false)) {
            return null;
        }

        return [...$settings, 'precision' => min(4, max(0, (int) (DB::table('currencies')
            ->where('code', $order->currency_code ?: $order->company->base_currency_code)
            ->value('decimal_places') ?? 2)))];
    }

    public function category(array $data): ?string
    {
        // Use structured order metadata; never infer tax from free-text descriptions.
        return match ($data['type'] ?? null) {
            'flight', 'pricing', 'pricing_total' => 'flights',
            'hotel' => 'hotels',
            'transfer' => 'transfers',
            'city_tour' => 'city_tours',
            'visa' => 'visa',
            'other_service' => 'other_services',
            default => null,
        };
    }

    public function totals(iterable $lines, array $settings, bool $refund = false): array
    {
        $sign = $refund ? -1 : 1;
        $positive = 0.0;
        $taxable = 0.0;
        $discount = 0.0;
        foreach ($lines as $line) {
            $amount = $sign * (float) $line['total_price'];
            if ($amount < 0) {
                $discount += abs($amount);

                continue;
            }
            $positive += $amount;
            if (in_array($line['tax_category'] ?? null, $settings['categories'], true)) {
                $taxable += $amount;
            }
        }

        if ($refund && isset($settings['refund_discount_ratio'])) {
            $discount = round($positive * (float) $settings['refund_discount_ratio'], 4);
        }

        // Invoice-wide discounts are allocated proportionally across all services.
        $remaining = max(0, $positive - $discount);
        $taxableAfterDiscount = $positive > 0 ? $remaining * $taxable / $positive : 0;
        $rate = (float) $settings['rate'];
        $inclusive = $settings['pricing'] === 'inclusive';
        $tax = round($taxableAfterDiscount * $rate / ($inclusive ? 100 + $rate : 100), $settings['precision'], PHP_ROUND_HALF_UP);
        $total = round($remaining + ($inclusive ? 0 : $tax), 4);

        return [
            'subtotal' => round($sign * $positive, 4),
            'tax_amount' => $sign * $tax,
            'total_amount' => $sign * $total,
            'taxable_amount' => round($sign * ($taxableAfterDiscount - ($inclusive ? $tax : 0)), 4),
        ];
    }

    public function refundSnapshot(Invoice $invoice): ?array
    {
        if (! $invoice->tax_settings) {
            return null;
        }
        $invoice->loadMissing('lines');
        $gross = (float) $invoice->lines->sum(fn ($line) => max(0, (float) $line->total_price));
        $discount = abs((float) $invoice->lines->sum(fn ($line) => min(0, (float) $line->total_price)));

        return [...$invoice->tax_settings, 'refund_discount_ratio' => $gross > 0 ? min(1, $discount / $gross) : 0];
    }

    public function syncRefund(Order $order): void
    {
        if (empty($order->meta['refund_of_invoice_id'])) {
            return;
        }
        $order->load('items');
        $totals = $this->refundTotals($order);
        $meta = $order->meta;
        $original = Invoice::where('tenant_id', $order->tenant_id)
            ->where('company_id', $order->company_id)
            ->findOrFail($meta['refund_of_invoice_id']);
        if ($order->currency_code !== $original->currency_code || (int) $order->customer_id !== (int) $original->customer_id) {
            throw ValidationException::withMessages(['currency_code' => 'A refund must retain the original invoice customer and currency.']);
        }
        $categoryAmounts = [];
        foreach ($order->items as $item) {
            if (($item->gds_data['type'] ?? null) === 'discount') {
                continue;
            }
            if ((float) $item->total_price > 0) {
                throw ValidationException::withMessages(['voucher' => 'Refund service amounts must be zero or negative.']);
            }
            $category = $this->category($item->gds_data ?? []) ?? 'uncategorized';
            $categoryAmounts[$category] = ($categoryAmounts[$category] ?? 0) + abs((float) $item->total_price);
        }
        foreach ($categoryAmounts as $category => $amount) {
            if ($amount > ($meta['refund_category_limits'][$category] ?? 0) + 0.00005) {
                throw ValidationException::withMessages(['voucher' => 'Refund services cannot exceed the original invoiced category amounts.']);
            }
        }
        $allocated = (float) RefundAllocation::where('tenant_id', $order->tenant_id)->where('order_id', $order->id)->where('allocation_type', RefundAllocation::CUSTOMER_PAYMENT)->sum('amount');
        if (abs($totals['total_amount']) + 0.00005 < $allocated) {
            throw ValidationException::withMessages(['voucher' => 'Refund cannot be reduced below its allocated balance.']);
        }
        $meta['invoice_tax_amount'] = $totals['tax_amount'];
        $meta['invoice_tax_totals'] = $totals;
        if ($meta['invoice_tax_settings'] ?? null) {
            $meta['invoice_tax_settings']['taxable_amount'] = $totals['taxable_amount'];
        }
        $order->update(['total_amount' => $totals['total_amount'], 'meta' => $meta]);
    }

    public function revenueForOrder(Order $order): float
    {
        if ($order->invoice) {
            return (float) $order->invoice->total_amount - (float) $order->invoice->tax_amount;
        }

        return (float) $order->total_amount - (float) ($order->meta['invoice_tax_amount'] ?? 0);
    }

    public function refundTotals(Order $order): ?array
    {
        $settings = $order->meta['invoice_tax_settings'] ?? null;
        if (! $settings && empty($order->meta['refund_of_invoice_id'])) {
            return null;
        }
        $settings ??= [...self::defaults(), 'precision' => 4, 'refund_discount_ratio' => $order->meta['refund_discount_ratio'] ?? 0];
        $order->loadMissing('items');
        $lines = $order->items->filter(fn ($item) => ($item->gds_data['type'] ?? null) !== 'discount')->map(fn ($item) => [
            'total_price' => $item->total_price,
            'tax_category' => $this->category($item->gds_data ?? []),
        ]);

        return $this->totals($lines, $settings, true);
    }
}
