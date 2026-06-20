<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReportService
{
    public function cashTransactionReport(
        int $tenantId,
        int $companyId,
        string $fromDate,
        string $toDate,
        string $direction,
        ?string $counterpartyType = null,
        ?string $paymentMethod = null,
        ?string $search = null
    ): array {
        $isReceipt = $direction === 'receipt';
        $model = $isReceipt ? Receipt::class : Payment::class;
        $dateColumn = $isReceipt ? 'receipt_date' : 'payment_date';
        $numberColumn = $isReceipt ? 'receipt_number' : 'payment_number';
        $query = $model::query()->where('tenant_id', $tenantId)->where('company_id', $companyId)
            ->whereBetween($dateColumn, [$fromDate, $toDate])
            ->when($counterpartyType === 'customer', fn ($q) => $q->whereNotNull('customer_id'))
            ->when($counterpartyType === 'vendor', fn ($q) => $q->whereNotNull('vendor_id'))
            ->when($paymentMethod, fn ($q) => $q->where('payment_method', $paymentMethod))
            ->when($search, function ($q, $search) use ($numberColumn) {
                $q->where(function ($nested) use ($search, $numberColumn) {
                    $nested->where($numberColumn, 'like', "%{$search}%")
                        ->orWhere('reference_number', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($party) => $party->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('vendor', fn ($party) => $party->where('name', 'like', "%{$search}%"));
                });
            })->with(['customer:id,name', 'vendor:id,name', 'createdBy:id,name']);

        if ($isReceipt) $query->with('settlements.invoice:id,invoice_number');
        else $query->with('allocations.order:id,order_number');

        $records = $query->orderByDesc($dateColumn)->orderByDesc('id')->get()->map(function ($record) use ($isReceipt, $dateColumn, $numberColumn) {
            $partyType = $record->customer_id ? 'customer' : 'vendor';
            return [
                'key' => ($isReceipt ? 'receipt-' : 'payment-') . $record->id,
                'direction' => $isReceipt ? 'money_in' : 'money_out',
                'counterparty_type' => $partyType,
                'counterparty_name' => $record->{$partyType}?->name,
                'document_number' => $record->{$numberColumn},
                'date' => $record->{$dateColumn}->toDateString(),
                'payment_method' => $record->payment_method,
                'reference_number' => $record->reference_number,
                'description' => $record->description,
                'currency_code' => $record->currency_code,
                'amount' => (float) $record->amount,
                'created_by' => $record->createdBy?->name,
            ];
        });
        $byCurrency = $records->groupBy('currency_code')->map(fn (Collection $rows, string $currency) => [
            'currency_code' => $currency, 'amount' => round((float) $rows->sum('amount'), 4), 'count' => $rows->count(),
        ])->values();
        return [
            'report_date' => now()->toDateString(), 'company_id' => $companyId, 'direction' => $direction,
            'period' => ['from' => $fromDate, 'to' => $toDate], 'data' => $records->all(),
            'summary' => [
                'total_records' => $records->count(),
                'customer_records' => $records->where('counterparty_type', 'customer')->count(),
                'vendor_records' => $records->where('counterparty_type', 'vendor')->count(),
                'by_currency' => $byCurrency->all(),
            ],
        ];
    }

    /**
     * Generate a unified report of customer receipts and vendor payments.
     */
    public function paymentReport(
        int $tenantId,
        int $companyId,
        string $fromDate,
        string $toDate,
        string $type = 'all',
        ?string $paymentMethod = null,
        ?string $search = null
    ): array {
        $records = collect();

        if (in_array($type, ['all', 'received'], true)) {
            $receipts = Receipt::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereBetween('receipt_date', [$fromDate, $toDate])
                ->when($paymentMethod, fn ($query) => $query->where('payment_method', $paymentMethod))
                ->when($search, function ($query, $search) {
                    $query->where(function ($searchQuery) use ($search) {
                        $searchQuery->where('receipt_number', 'like', "%{$search}%")
                            ->orWhere('reference_number', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', "%{$search}%"));
                    });
                })
                ->with(['customer:id,name', 'createdBy:id,name', 'settlements.invoice:id,invoice_number'])
                ->get()
                ->map(fn (Receipt $receipt) => [
                    'key' => 'receipt-' . $receipt->id,
                    'type' => 'received',
                    'document_number' => $receipt->receipt_number,
                    'date' => $receipt->receipt_date->toDateString(),
                    'party_type' => 'customer',
                    'party_name' => $receipt->customer?->name,
                    'payment_method' => $receipt->payment_method,
                    'reference_number' => $receipt->reference_number,
                    'related_documents' => $receipt->settlements
                        ->pluck('invoice.invoice_number')->filter()->unique()->values()->all(),
                    'description' => $receipt->description,
                    'currency_code' => $receipt->currency_code,
                    'amount' => (float) $receipt->amount,
                    'created_by' => $receipt->createdBy?->name,
                ]);

            $records = $records->concat($receipts);
        }

        if (in_array($type, ['all', 'paid'], true)) {
            $payments = Payment::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereBetween('payment_date', [$fromDate, $toDate])
                ->when($paymentMethod, fn ($query) => $query->where('payment_method', $paymentMethod))
                ->when($search, function ($query, $search) {
                    $query->where(function ($searchQuery) use ($search) {
                        $searchQuery->where('payment_number', 'like', "%{$search}%")
                            ->orWhere('reference_number', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhereHas('vendor', fn ($vendorQuery) => $vendorQuery->where('name', 'like', "%{$search}%"));
                    });
                })
                ->with(['vendor:id,name', 'createdBy:id,name', 'allocations.order:id,order_number'])
                ->get()
                ->map(fn (Payment $payment) => [
                    'key' => 'payment-' . $payment->id,
                    'type' => 'paid',
                    'document_number' => $payment->payment_number,
                    'date' => $payment->payment_date->toDateString(),
                    'party_type' => 'vendor',
                    'party_name' => $payment->vendor?->name,
                    'payment_method' => $payment->payment_method,
                    'reference_number' => $payment->reference_number,
                    'related_documents' => $payment->allocations
                        ->pluck('order.order_number')->filter()->unique()->values()->all(),
                    'description' => $payment->description,
                    'currency_code' => $payment->currency_code,
                    'amount' => (float) $payment->amount,
                    'created_by' => $payment->createdBy?->name,
                ]);

            $records = $records->concat($payments);
        }

        $records = $records->sortByDesc(fn (array $record) => $record['date'] . '|' . $record['key'])->values();
        $currencySummary = $records->groupBy('currency_code')->map(function (Collection $currencyRecords, string $currency) {
            return [
                'currency_code' => $currency,
                'received' => round((float) $currencyRecords->where('type', 'received')->sum('amount'), 4),
                'paid' => round((float) $currencyRecords->where('type', 'paid')->sum('amount'), 4),
                'net_cash_flow' => round(
                    (float) $currencyRecords->where('type', 'received')->sum('amount')
                    - (float) $currencyRecords->where('type', 'paid')->sum('amount'),
                    4
                ),
            ];
        })->values();

        return [
            'report_date' => now()->toDateString(),
            'company_id' => $companyId,
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'filters' => [
                'type' => $type,
                'payment_method' => $paymentMethod,
                'search' => $search,
            ],
            'data' => $records->all(),
            'summary' => [
                'total_records' => $records->count(),
                'received_count' => $records->where('type', 'received')->count(),
                'paid_count' => $records->where('type', 'paid')->count(),
                'by_currency' => $currencySummary->all(),
            ],
        ];
    }

    /**
     * Generate invoice aging report
     */
    public function invoiceAgingReport(int $tenantId, int $companyId): array
    {
        $invoices = Invoice::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn('status', ['issued', 'sent', 'partial_paid', 'overdue'])
            ->with(['customer', 'order'])
            ->get();

        $current = collect([]);
        $days30 = collect([]);
        $days60 = collect([]);
        $days90 = collect([]);
        $overdue = collect([]);

        $today = now();

        foreach ($invoices as $invoice) {
            $daysOverdue = Carbon::parse($invoice->due_date)->diffInDays($today, false);

            $record = [
                'invoice_uid' => $invoice->uid,
                'invoice_number' => $invoice->invoice_number,
                'customer_name' => $invoice->customer->name,
                'invoice_date' => $invoice->invoice_date,
                'due_date' => $invoice->due_date,
                'amount' => $invoice->total_amount,
                'outstanding' => $invoice->outstanding_amount,
                'days_overdue' => $daysOverdue,
            ];

            if ($daysOverdue > 90) {
                $overdue->push($record);
            } elseif ($daysOverdue > 60) {
                $days90->push($record);
            } elseif ($daysOverdue > 30) {
                $days60->push($record);
            } elseif ($daysOverdue > 0) {
                $days30->push($record);
            } else {
                $current->push($record);
            }
        }

        return [
            'report_date' => now()->toDateString(),
            'company_id' => $companyId,
            'buckets' => [
                'current' => [
                    'description' => 'Not yet due',
                    'invoices' => $current->toArray(),
                    'total_outstanding' => $current->sum('outstanding'),
                    'count' => $current->count(),
                ],
                'days_1_30' => [
                    'description' => '1-30 days overdue',
                    'invoices' => $days30->toArray(),
                    'total_outstanding' => $days30->sum('outstanding'),
                    'count' => $days30->count(),
                ],
                'days_31_60' => [
                    'description' => '31-60 days overdue',
                    'invoices' => $days60->toArray(),
                    'total_outstanding' => $days60->sum('outstanding'),
                    'count' => $days60->count(),
                ],
                'days_61_90' => [
                    'description' => '61-90 days overdue',
                    'invoices' => $days90->toArray(),
                    'total_outstanding' => $days90->sum('outstanding'),
                    'count' => $days90->count(),
                ],
                'days_over_90' => [
                    'description' => 'Over 90 days overdue',
                    'invoices' => $overdue->toArray(),
                    'total_outstanding' => $overdue->sum('outstanding'),
                    'count' => $overdue->count(),
                ],
            ],
            'summary' => [
                'total_outstanding' => $invoices->sum('outstanding_amount'),
                'total_invoices' => $invoices->count(),
            ],
        ];
    }

    /**
     * Generate revenue report by period
     */
    public function revenueReport(
        int $tenantId,
        int $companyId,
        string $fromDate,
        string $toDate,
        string $groupBy = 'month'
    ): array {
        $invoices = Invoice::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('invoice_date', '>=', $fromDate)
            ->where('invoice_date', '<=', $toDate)
            ->with(['customer'])
            ->get();

        $grouped = match($groupBy) {
            'day' => $invoices->groupBy(fn($i) => Carbon::parse($i->invoice_date)->toDateString()),
            'week' => $invoices->groupBy(fn($i) => Carbon::parse($i->invoice_date)->weekOfYear),
            'month' => $invoices->groupBy(fn($i) => Carbon::parse($i->invoice_date)->format('Y-m')),
            'year' => $invoices->groupBy(fn($i) => Carbon::parse($i->invoice_date)->year),
            default => $invoices,
        };

        $report = [];
        foreach ($grouped as $period => $items) {
            $report[] = [
                'period' => $period,
                'total_revenue' => $items->sum('total_amount'),
                'total_paid' => $items->sum(fn($i) => $i->total_amount - $i->outstanding_amount),
                'total_outstanding' => $items->sum('outstanding_amount'),
                'invoice_count' => $items->count(),
                'average_invoice_value' => $items->avg('total_amount'),
            ];
        }

        return [
            'report_date' => now()->toDateString(),
            'company_id' => $companyId,
            'period' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'group_by' => $groupBy,
            'data' => $report,
            'summary' => [
                'total_revenue' => $invoices->sum('total_amount'),
                'total_collected' => $invoices->sum(fn($i) => $i->total_amount - $i->outstanding_amount),
                'total_outstanding' => $invoices->sum('outstanding_amount'),
                'total_invoices' => $invoices->count(),
            ],
        ];
    }

    /**
     * Generate customer summary report
     */
    public function customerSummaryReport(int $tenantId, int $companyId): array
    {
        $customers = \App\Models\Customer::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with(['invoices'])
            ->get();

        $customerData = $customers->map(function ($customer) {
            $invoices = $customer->invoices;
            return [
                'customer_uid' => $customer->uid,
                'customer_name' => $customer->name,
                'customer_type' => $customer->type,
                'email' => $customer->email,
                'total_invoiced' => $invoices->sum('total_amount'),
                'total_paid' => $invoices->sum(fn($i) => $i->total_amount - $i->outstanding_amount),
                'total_outstanding' => $invoices->sum('outstanding_amount'),
                'outstanding_percentage' => $invoices->sum('total_amount') > 0
                    ? round(($invoices->sum('outstanding_amount') / $invoices->sum('total_amount')) * 100, 2)
                    : 0,
                'invoice_count' => $invoices->count(),
                'last_invoice_date' => $invoices->max('invoice_date'),
            ];
        });

        return [
            'report_date' => now()->toDateString(),
            'company_id' => $companyId,
            'customers' => $customerData->toArray(),
            'summary' => [
                'total_customers' => $customers->count(),
                'total_revenue' => $customerData->sum('total_invoiced'),
                'total_outstanding' => $customerData->sum('total_outstanding'),
            ],
        ];
    }
}
