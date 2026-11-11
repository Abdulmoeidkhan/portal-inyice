<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Vendor;

class PartyBalanceReportService
{
    public function report(int $tenantId, int $companyId): array
    {
        $currency = Company::where('tenant_id', $tenantId)->findOrFail($companyId)->base_currency_code;
        $balances = [];
        // Positive customer balances are receivable; positive vendor balances are payable.
        $add = function (string $type, int $id, ?string $code, float $amount) use (&$balances, $currency): void {
            $code = $code ?: $currency;
            $key = "$type:$id:$code";
            $balances[$key] ??= ['key' => $key, 'type' => $type, 'party_id' => $id, 'currency' => $code, 'balance' => 0];
            $balances[$key]['balance'] += $amount;
        };

        Invoice::where('tenant_id', $tenantId)->where('company_id', $companyId)
            ->whereNotIn('status', ['void', 'cancel'])->whereHas('order')
            ->selectRaw('customer_id, currency_code, SUM(total_amount) as amount')
            ->groupBy('customer_id', 'currency_code')->get()
            ->each(fn ($row) => $add('customer', (int) $row->customer_id, $row->currency_code, (float) $row->amount));

        Order::where('tenant_id', $tenantId)->where('company_id', $companyId)
            ->whereIn('status', ['partial_refund', 'refund'])->where('total_amount', '<', 0)
            ->whereDoesntHave('invoice', fn ($query) => $query->whereNotIn('status', ['void', 'cancel']))
            ->selectRaw('customer_id, currency_code, SUM(total_amount) as amount')
            ->groupBy('customer_id', 'currency_code')->get()
            ->each(fn ($row) => $add('customer', (int) $row->customer_id, $row->currency_code, (float) $row->amount));

        // Vendor costs are stored in company base currency, as in the vendor statement.
        Order::where('tenant_id', $tenantId)->where('company_id', $companyId)
            ->where(fn ($query) => $query
                ->whereHas('invoice', fn ($invoice) => $invoice->whereNotIn('status', ['void', 'cancel']))
                ->orWhereIn('status', ['partial_refund', 'refund']))
            ->with('vendorCosts')->get()->each(function (Order $order) use ($add, $currency): void {
                if ($order->vendorCosts->isNotEmpty()) {
                    foreach ($order->vendorCosts->groupBy('vendor_id') as $vendorId => $costs) {
                        $add('vendor', (int) $vendorId, $currency, (float) $costs->sum('amount'));
                    }
                    return;
                }
                $vendorIds = collect(Order::vendorCostRowsFromVoucher($order->meta ?? [], $order->id, $order->tenant_id, $order->vendor_id))->pluck('vendor_id');
                if ($order->vendor_id) {
                    $vendorIds->push($order->vendor_id);
                }
                foreach ($vendorIds->unique() as $vendorId) {
                    $add('vendor', (int) $vendorId, $currency, $order->vendorPayableAmountFor((int) $vendorId));
                }
            });

        foreach ([Receipt::class => -1, Payment::class => 1] as $model => $sign) {
            foreach (['customer', 'vendor'] as $type) {
                $column = $type . '_id';
                $model::where('tenant_id', $tenantId)->where('company_id', $companyId)->whereNotNull($column)
                    ->selectRaw("$column, currency_code, SUM(amount) as amount")
                    ->groupBy($column, 'currency_code')->get()
                    ->each(fn ($row) => $add($type, (int) $row->{$column}, $row->currency_code,
                        (float) $row->amount * $sign * ($type === 'customer' ? 1 : -1)));
            }
        }

        $parties = [
            'customer' => Customer::where('tenant_id', $tenantId)->where('company_id', $companyId)->get(['id', 'name', 'phone'])->keyBy('id'),
            'vendor' => Vendor::where('tenant_id', $tenantId)->where('company_id', $companyId)->get(['id', 'name', 'phone'])->keyBy('id'),
        ];
        $rows = collect($balances)->filter(fn ($row) => isset($parties[$row['type']][$row['party_id']]))
            ->map(function ($row) use ($parties) {
                $party = $parties[$row['type']][$row['party_id']];
                $balance = round($row['balance'], 4);
                $receivable = $row['type'] === 'customer' ? $balance : -$balance;
                return [...$row, 'name' => $party->name, 'phone' => $party->phone,
                    'balance' => $balance, 'receivable' => max(0, $receivable), 'payable' => max(0, -$receivable)];
            })->filter(fn ($row) => $row['balance'] != 0)->sortByDesc(fn ($row) => abs($row['balance']))->values();

        return [
            'generated_at' => now()->toIso8601String(),
            'base_currency' => $currency,
            'rows' => $rows->all(),
            'totals' => $rows->groupBy('currency')->map(fn ($items, $code) => [
                'currency' => $code,
                'receivable' => round($items->sum('receivable'), 4),
                'payable' => round($items->sum('payable'), 4),
            ])->values()->all(),
        ];
    }
}
