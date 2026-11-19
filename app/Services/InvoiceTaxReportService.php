<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InvoiceTaxReportService
{
    public function report(int $tenantId, int $companyId, array $filters): array
    {
        // Read saved snapshots only. Payments do not create additional tax records.
        $invoices = Invoice::where('tenant_id', $tenantId)->where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'void', 'cancel'])
            ->whereNotNull('tax_settings')
            ->whereDate('invoice_date', '>=', $filters['from_date'])
            ->whereDate('invoice_date', '<=', $filters['to_date'])
            ->when($filters['currency_code'] ?? null, fn ($q, $currency) => $q->where('currency_code', $currency))
            ->with(['customer:id,name', 'order:id,uid,order_number,status'])->get();

        $rows = collect();
        foreach ($invoices as $invoice) {
            if (! ($invoice->tax_settings['enabled'] ?? false)) {
                continue;
            }
            if ((float) $invoice->total_amount < 0 && ! in_array($invoice->order?->status, ['partial_refund', 'refund'], true)) {
                continue;
            }
            $rows->push($this->row(
                'invoice-'.$invoice->id, $invoice->uid, $invoice->invoice_number,
                $invoice->invoice_date->toDateString(), $invoice->customer?->name,
                $invoice->order?->order_number, $invoice->currency_code,
                $invoice->tax_settings, (float) $invoice->tax_amount, (float) $invoice->total_amount,
                (float) $invoice->total_amount < 0 ? 'refund' : 'invoice',
            ));
        }

        // Confirmed refund orders appear as virtual invoices until a physical invoice exists.
        $refunds = Order::where('tenant_id', $tenantId)->where('company_id', $companyId)
            ->whereIn('status', ['partial_refund', 'refund'])->where('total_amount', '<', 0)
            ->whereDate('created_at', '>=', $filters['from_date'])
            ->whereDate('created_at', '<=', $filters['to_date'])
            ->whereNotNull('meta->invoice_tax_settings')
            ->whereDoesntHave('invoice', fn ($q) => $q->whereNotIn('status', ['void', 'cancel']))
            ->when($filters['currency_code'] ?? null, fn ($q, $currency) => $q->where('currency_code', $currency))
            ->with('customer:id,name')->get();
        foreach ($refunds as $order) {
            $settings = $order->meta['invoice_tax_settings'] ?? null;
            if (! ($settings['enabled'] ?? false)) {
                continue;
            }
            $rows->push($this->row(
                'refund-order-'.$order->id, $order->uid, 'Refund '.$order->order_number,
                $order->created_at->toDateString(), $order->customer?->name,
                $order->order_number, $order->currency_code, $settings,
                (float) ($order->meta['invoice_tax_amount'] ?? 0), (float) $order->total_amount, 'refund',
            ));
        }

        $rows = $rows->filter(function (array $row) use ($filters): bool {
            if (! empty($filters['type']) && $row['type'] !== $filters['type']) {
                return false;
            }
            if (! empty($filters['pricing']) && $row['pricing'] !== $filters['pricing']) {
                return false;
            }
            $search = trim($filters['search'] ?? '');

            return $search === '' || Str::contains(implode(' ', [$row['reference'], $row['order_number'], $row['customer_name'], $row['tax_label'], $row['registration_number']]), $search, ignoreCase: true);
        })->sortByDesc(fn ($row) => $row['date'].'|'.$row['key'])->values();

        return [
            'period' => ['from' => $filters['from_date'], 'to' => $filters['to_date']],
            'data' => isset($filters['page']) ? $rows->forPage($filters['page'], $filters['per_page'] ?? 25)->values()->all() : $rows->all(),
            'total' => $rows->count(),
            'summary' => [
                'total_records' => $rows->count(),
                'invoice_count' => $rows->where('type', 'invoice')->count(),
                'refund_count' => $rows->where('type', 'refund')->count(),
                'by_currency' => $rows->groupBy('currency_code')->map(fn (Collection $group, string $currency) => [
                    'currency_code' => $currency,
                    'tax_charged' => round((float) $group->where('type', 'invoice')->sum('tax_amount'), 4),
                    'tax_reversed' => round(abs((float) $group->where('type', 'refund')->sum('tax_amount')), 4),
                    'net_tax' => round((float) $group->sum('tax_amount'), 4),
                    'taxable_amount' => round((float) $group->sum('taxable_amount'), 4),
                ])->sortKeys()->values()->all(),
            ],
        ];
    }

    private function row(string $key, string $uid, string $reference, string $date, ?string $customer, ?string $order, string $currency, array $settings, float $tax, float $total, string $type): array
    {
        return [
            'key' => $key, 'invoice_uid' => $uid, 'reference' => $reference, 'date' => $date,
            'customer_name' => $customer, 'order_number' => $order, 'currency_code' => $currency,
            'type' => $type, 'tax_label' => $settings['name'], 'registration_number' => $settings['registration_number'] ?? null,
            'rate' => $settings['rate'], 'pricing' => $settings['pricing'],
            'taxable_amount' => (float) ($settings['taxable_amount'] ?? 0),
            'tax_amount' => $tax, 'net_amount' => round($total - $tax, 4), 'total_amount' => $total,
        ];
    }
}
