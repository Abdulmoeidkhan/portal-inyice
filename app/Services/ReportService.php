<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderVendorCost;
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
        ?int $counterpartyId = null,
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
            ->when($counterpartyType === 'customer' && $counterpartyId, fn ($q) => $q->where('customer_id', $counterpartyId))
            ->when($counterpartyType === 'vendor' && $counterpartyId, fn ($q) => $q->where('vendor_id', $counterpartyId))
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

    public function cancelledInvoiceReport(
        ?int $tenantId,
        ?int $companyId,
        string $fromDate,
        string $toDate,
        ?string $search = null,
        bool $allTenants = false
    ): array {
        $query = ($allTenants ? Invoice::withoutGlobalScopes() : Invoice::query())
            ->where('status', 'cancel')
            ->whereBetween('invoice_date', [$fromDate, $toDate])
            ->with([
                'tenant:id,name,code',
                'company:id,tenant_id,display_name,legal_name',
                'customer:id,name',
                'order:id,order_number,booking_reference',
            ])
            ->when(!$allTenants, fn ($q) => $q->where('tenant_id', $tenantId)->where('company_id', $companyId))
            ->when($search, function ($q, $search) {
                $q->where(function ($nested) use ($search) {
                    $nested->where('invoice_number', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('company', fn ($company) => $company->where('display_name', 'like', "%{$search}%")->orWhere('legal_name', 'like', "%{$search}%"))
                        ->orWhereHas('order', fn ($order) => $order->where('order_number', 'like', "%{$search}%")->orWhere('booking_reference', 'like', "%{$search}%"));
                });
            });

        $records = $query->orderByDesc('invoice_date')->orderByDesc('id')->get()->map(fn (Invoice $invoice) => [
            'uid' => $invoice->uid,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'tenant_name' => $invoice->tenant?->name,
            'company_name' => $invoice->company?->display_name ?: $invoice->company?->legal_name,
            'customer_name' => $invoice->customer?->name,
            'order_number' => $invoice->order?->order_number,
            'booking_reference' => $invoice->order?->booking_reference,
            'currency_code' => $invoice->currency_code,
            'total_amount' => (float) $invoice->total_amount,
            'outstanding_amount' => (float) $invoice->outstanding_amount,
            'notes' => $invoice->notes,
        ]);

        return [
            'report_date' => now()->toDateString(),
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'scope' => $allTenants ? 'all_companies' : 'current_company',
            'data' => $records->all(),
            'summary' => [
                'total_records' => $records->count(),
                'by_currency' => $records->groupBy('currency_code')->map(fn (Collection $rows, string $currency) => [
                    'currency_code' => $currency,
                    'amount' => round((float) $rows->sum('total_amount'), 4),
                    'count' => $rows->count(),
                ])->values()->all(),
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
            ->whereHas('invoices', fn ($invoice) => $invoice
                ->where('company_id', $companyId)
                ->where('status', '!=', 'void')
                ->whereHas('order'))
            ->with(['invoices' => fn ($invoice) => $invoice
                ->where('company_id', $companyId)
                ->where('status', '!=', 'void')
                ->whereHas('order')])
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

    public function dashboardUpcoming(int $tenantId, int $companyId, int $days = 30, bool $includeFinance = false): array
    {
        $days = max(1, min($days, 90));
        $from = now()->startOfDay();
        $to = now()->addDays($days)->endOfDay();

        $events = collect();
        $orders = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereNotIn('status', ['cancel', 'void'])
            ->with('customer:id,name')
            ->orderByDesc('created_at')
            ->get(['id', 'uid', 'order_number', 'voucher_no', 'booking_reference', 'customer_id', 'status', 'meta', 'created_at']);

        foreach ($orders as $order) {
            $meta = is_array($order->meta) ? $order->meta : [];

            foreach (($meta['flights'] ?? []) as $index => $flight) {
                if (!is_array($flight)) {
                    continue;
                }

                $date = $this->dashboardDate($flight['date'] ?? null);
                if (!$date || !$date->betweenIncluded($from, $to)) {
                    continue;
                }

                $events->push($this->dashboardEvent($order, 'departure', $date, [
                    'time' => $flight['departure'] ?? null,
                    'title' => trim(sprintf(
                        'Flight %s %s-%s',
                        $flight['flight_no'] ?? '-',
                        strtoupper((string) ($flight['from'] ?? '-')),
                        strtoupper((string) ($flight['to'] ?? '-'))
                    )),
                    'description' => trim((string) ($flight['pnr'] ?? $flight['gds_pnr'] ?? $order->booking_reference ?? '')),
                    'source_index' => $index,
                ]));
            }

            foreach (($meta['hotels'] ?? []) as $index => $hotel) {
                if (!is_array($hotel)) {
                    continue;
                }

                foreach (['checkin' => 'check_in', 'checkout' => 'check_out'] as $type => $field) {
                    $date = $this->dashboardDate($hotel[$field] ?? null);
                    if (!$date || !$date->betweenIncluded($from, $to)) {
                        continue;
                    }

                    $events->push($this->dashboardEvent($order, $type, $date, [
                        'title' => trim((string) ($hotel['hotel_name'] ?? 'Hotel')),
                        'description' => trim(collect([$hotel['city'] ?? null, $hotel['lead_passenger'] ?? null])->filter()->join(' - ')),
                        'source_index' => $index,
                    ]));
                }
            }
        }

        $events = $events
            ->sortBy(fn (array $event) => $event['date'] . ' ' . ($event['time'] ?: '00:00') . ' ' . $event['order_number'])
            ->values();

        $byType = $events->groupBy('type');
        $notifications = $events
            ->values()
            ->take(12)
            ->map(fn (array $event) => [
                ...$event,
                'message' => $this->dashboardNotificationMessage($event),
                'severity' => $this->dashboardUrgency($event['days_until']),
            ])
            ->values();

        $dashboard = [
            'report_date' => now()->toDateString(),
            'company_id' => $companyId,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'summary' => [
                'departures' => $byType->get('departure', collect())->count(),
                'checkins' => $byType->get('checkin', collect())->count(),
                'checkouts' => $byType->get('checkout', collect())->count(),
                'notifications' => $notifications->count(),
            ],
            'departures' => $byType->get('departure', collect())->values()->all(),
            'checkins' => $byType->get('checkin', collect())->values()->all(),
            'checkouts' => $byType->get('checkout', collect())->values()->all(),
            'notifications' => $notifications->all(),
        ];

        if ($includeFinance) {
            $dashboard['finance'] = $this->dashboardFinance($tenantId, $companyId);
        }

        return $dashboard;
    }

    private function dashboardFinance(int $tenantId, int $companyId): array
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();
        $cashflowFrom = now()->subDays(9)->toDateString();
        $cashflowTo = now()->toDateString();

        $invoice = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereBetween('invoice_date', [$from, $to])
            ->whereNotIn('status', ['void', 'cancel'])
            ->selectRaw('COALESCE(SUM(total_amount), 0) as invoiced')
            ->selectRaw('COALESCE(SUM(total_amount - outstanding_amount), 0) as collected')
            ->selectRaw('COALESCE(SUM(outstanding_amount), 0) as outstanding')
            ->first();

        $cashIn = Receipt::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereBetween('receipt_date', [$from, $to])
            ->sum('amount');

        $cashOut = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereBetween('payment_date', [$from, $to])
            ->sum('amount');

        $costQuery = OrderVendorCost::query()
            ->join('orders', 'orders.id', '=', 'order_vendor_costs.order_id')
            ->join('invoices', 'invoices.order_id', '=', 'orders.id')
            ->where('order_vendor_costs.tenant_id', $tenantId)
            ->where('orders.company_id', $companyId)
            ->whereBetween('invoices.invoice_date', [$from, $to])
            ->whereNotIn('invoices.status', ['void', 'cancel'])
            ->whereNotIn('orders.status', ['cancel', 'void']);

        $cost = (clone $costQuery)->sum('order_vendor_costs.amount');
        $expenses = (clone $costQuery)
            ->selectRaw("COALESCE(NULLIF(order_vendor_costs.service_type, ''), 'Other') as type")
            ->selectRaw('COALESCE(SUM(order_vendor_costs.amount), 0) as value')
            ->groupBy('order_vendor_costs.service_type')
            ->orderByDesc('value')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'type' => ucwords(str_replace('_', ' ', $row->type ?: 'Other')),
                'value' => round((float) $row->value, 2),
            ])
            ->values();

        $receiptsByDate = Receipt::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereBetween('receipt_date', [$cashflowFrom, $cashflowTo])
            ->selectRaw('receipt_date as date, COALESCE(SUM(amount), 0) as total')
            ->groupBy('receipt_date')
            ->pluck('total', 'date');

        $paymentsByDate = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereBetween('payment_date', [$cashflowFrom, $cashflowTo])
            ->selectRaw('payment_date as date, COALESCE(SUM(amount), 0) as total')
            ->groupBy('payment_date')
            ->pluck('total', 'date');

        $cashflow = collect(range(0, 9))->map(function (int $index) use ($cashflowFrom, $receiptsByDate, $paymentsByDate) {
            $date = Carbon::parse($cashflowFrom)->addDays($index);
            $key = $date->toDateString();

            return [
                'date' => $key,
                'label' => $date->format('M j'),
                'inflow' => round((float) ($receiptsByDate[$key] ?? 0), 2),
                'outflow' => round((float) ($paymentsByDate[$key] ?? 0), 2),
            ];
        });

        $revenue = (float) $invoice->invoiced;
        $profit = $revenue - (float) $cost;

        return [
            'period' => ['from' => $from, 'to' => $to],
            'summary' => [
                'invoiced' => round($revenue, 2),
                'collected' => round((float) $invoice->collected, 2),
                'outstanding' => round((float) $invoice->outstanding, 2),
                'cash_in' => round((float) $cashIn, 2),
                'cash_out' => round((float) $cashOut, 2),
                'profit' => round($profit, 2),
                'margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
            ],
            'mix' => [
                ['type' => 'Collected', 'value' => round((float) $invoice->collected, 2)],
                ['type' => 'Outstanding', 'value' => round((float) $invoice->outstanding, 2)],
                ['type' => 'Cash Out', 'value' => round((float) $cashOut, 2)],
                ['type' => 'Profit', 'value' => round(max($profit, 0), 2)],
            ],
            'expenses' => $expenses->all(),
            'flow' => [
                ['type' => 'Cash In', 'value' => round((float) $cashIn, 2)],
                ['type' => 'Cash Out', 'value' => round((float) $cashOut, 2)],
            ],
            'cashflow' => $cashflow->all(),
        ];
    }

    public function profitReport(
        int $tenantId,
        int $companyId,
        string $fromDate,
        string $toDate,
        string $dateBy = 'invoice',
        string $groupBy = 'customer',
        ?int $entityId = null,
        ?string $search = null
    ): array {
        $orders = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where(function ($query) use ($fromDate, $toDate, $dateBy): void {
                $query->whereHas('invoice', function ($invoice) use ($fromDate, $toDate, $dateBy) {
                    $invoice->whereNotIn('status', ['void', 'cancel']);

                    if ($dateBy === 'invoice') {
                        $invoice->whereBetween('invoice_date', [$fromDate, $toDate]);
                    }
                })->orWhere(function ($refundQuery) use ($fromDate, $toDate, $dateBy): void {
                    $refundQuery->whereIn('status', ['refund_request', 'partial_refund', 'refund']);

                    if ($dateBy === 'invoice') {
                        $refundQuery->whereBetween('created_at', [
                            Carbon::parse($fromDate)->startOfDay(),
                            Carbon::parse($toDate)->endOfDay(),
                        ]);
                    }
                });
            })
            ->when($dateBy === 'creation', fn ($query) => $query->whereBetween('created_at', [
                Carbon::parse($fromDate)->startOfDay(),
                Carbon::parse($toDate)->endOfDay(),
            ]))
            ->when($entityId && $groupBy === 'customer', fn ($query) => $query->where('customer_id', $entityId))
            ->when($entityId && $groupBy === 'staff', fn ($query) => $query->where('created_by_user_id', $entityId))
            ->when($entityId && $groupBy === 'vendor', function ($query) use ($entityId) {
                $query->where(function ($vendorQuery) use ($entityId) {
                    $vendorQuery->where('vendor_id', $entityId)
                        ->orWhereHas('vendorCosts', fn ($costQuery) => $costQuery->where('vendor_id', $entityId));
                });
            })
            ->when($search, function ($query, string $search) {
                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery->where('order_number', 'like', "%{$search}%")
                        ->orWhere('voucher_no', 'like', "%{$search}%")
                        ->orWhere('booking_reference', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('vendor', fn ($vendor) => $vendor->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('createdBy', fn ($user) => $user->where('name', 'like', "%{$search}%"));
                });
            })
            ->with([
                'customer:id,name',
                'vendor:id,name',
                'createdBy:id,name',
                'vendorCosts.vendor:id,name',
                'invoice:id,order_id,invoice_number,invoice_date,status',
            ])
            ->orderByDesc(
                Invoice::select('invoice_date')
                    ->whereColumn('invoices.order_id', 'orders.id')
                    ->limit(1)
            )
            ->orderByDesc('created_at')
            ->get();

        if (in_array($dateBy, ['departure', 'checkin', 'service'], true)) {
            $orders = $orders
                ->filter(fn (Order $order) => $this->orderMatchesVoucherDateRange($order, $dateBy, $fromDate, $toDate))
                ->values();
        }

        $detailRows = collect();

        foreach ($orders as $order) {
            $revenue = (float) $order->total_amount;
            $cost = (float) $order->vendorCosts->sum('amount');
            if ($cost == 0.0) {
                $cost = $this->voucherCostTotal($order);
            }
            $currency = $order->currency_code;
            $date = $order->invoice?->invoice_date?->toDateString() ?? $order->issue_date?->toDateString() ?? $order->created_at->toDateString();

            if ($groupBy === 'vendor') {
                if ($order->vendorCosts->isEmpty()) {
                    if ($entityId && (int) $order->vendor_id !== $entityId) {
                        continue;
                    }

                    $fallbackCost = $this->voucherCostTotal($order);
                    $detailRows->push($this->profitRow(
                        $order,
                        'vendor',
                        $order->vendor_id,
                        $order->vendor?->name ?? 'Unassigned vendor',
                        $date,
                        $currency,
                        $revenue,
                        $fallbackCost
                    ));
                    continue;
                }

                $costRowsForReport = $entityId
                    ? $order->vendorCosts->where('vendor_id', $entityId)
                    : $order->vendorCosts;

                $costByVendor = $costRowsForReport->groupBy('vendor_id');
                foreach ($costByVendor as $vendorId => $costRows) {
                    $vendorCost = (float) $costRows->sum('amount');
                    $allocatedRevenue = $cost != 0.0 ? $revenue * (abs($vendorCost) / abs($cost)) : 0.0;
                    $detailRows->push($this->profitRow(
                        $order,
                        'vendor',
                        (int) $vendorId,
                        $costRows->first()?->vendor?->name ?? 'Unassigned vendor',
                        $date,
                        $currency,
                        $allocatedRevenue,
                        $vendorCost
                    ));
                }
                continue;
            }

            $entityId = $groupBy === 'staff' ? $order->created_by_user_id : $order->customer_id;
            $entityName = $groupBy === 'staff'
                ? ($order->createdBy?->name ?? 'Unassigned staff')
                : ($order->customer?->name ?? 'Unassigned customer');

            $detailRows->push($this->profitRow($order, $groupBy, $entityId, $entityName, $date, $currency, $revenue, $cost));
        }

        $groups = $detailRows
            ->groupBy(fn (array $row) => $row['group_id'] . '|' . $row['currency_code'])
            ->map(function (Collection $rows) {
                $revenue = (float) $rows->sum('revenue');
                $cost = (float) $rows->sum('cost');

                return [
                    'key' => $rows->first()['group_id'] . '-' . $rows->first()['currency_code'],
                    'group_id' => $rows->first()['group_id'],
                    'group_name' => $rows->first()['group_name'],
                    'currency_code' => $rows->first()['currency_code'],
                    'order_count' => $rows->pluck('order_id')->unique()->count(),
                    'revenue' => round($revenue, 4),
                    'cost' => round($cost, 4),
                    'profit' => round($revenue - $cost, 4),
                    'profit_margin' => $revenue > 0 ? round((($revenue - $cost) / $revenue) * 100, 2) : 0,
                ];
            })
            ->sortByDesc('profit')
            ->values();

        $currencySummary = $detailRows
            ->groupBy('currency_code')
            ->map(function (Collection $rows, string $currency) {
                $revenue = (float) $rows->sum('revenue');
                $cost = (float) $rows->sum('cost');

                return [
                    'currency_code' => $currency,
                    'revenue' => round($revenue, 4),
                    'cost' => round($cost, 4),
                    'profit' => round($revenue - $cost, 4),
                    'profit_margin' => $revenue > 0 ? round((($revenue - $cost) / $revenue) * 100, 2) : 0,
                ];
            })
            ->values();

        return [
            'report_date' => now()->toDateString(),
            'company_id' => $companyId,
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'filters' => ['date_by' => $dateBy, 'group_by' => $groupBy, 'entity_id' => $entityId, 'search' => $search],
            'data' => $groups->all(),
            'details' => $detailRows->values()->all(),
            'summary' => [
                'total_orders' => $orders->count(),
                'total_rows' => $detailRows->count(),
                'by_currency' => $currencySummary->all(),
            ],
        ];
    }

    private function dashboardDate(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function dashboardEvent(Order $order, string $type, Carbon $date, array $data): array
    {
        $daysUntil = now()->startOfDay()->diffInDays($date, false);

        return [
            'key' => $type . '-' . $order->id . '-' . ($data['source_index'] ?? 0) . '-' . $date->toDateString(),
            'type' => $type,
            'date' => $date->toDateString(),
            'time' => $data['time'] ?? null,
            'days_until' => $daysUntil,
            'relative_label' => $this->dashboardRelativeLabel($daysUntil),
            'title' => $data['title'] ?: ucfirst($type),
            'description' => $data['description'] ?: null,
            'order_uid' => $order->uid,
            'order_number' => $order->order_number,
            'voucher_no' => $order->voucher_no,
            'booking_reference' => $order->booking_reference,
            'customer_name' => $order->customer?->name,
            'status' => $order->status,
        ];
    }

    private function dashboardRelativeLabel(int $daysUntil): string
    {
        return match (true) {
            $daysUntil < 0 => abs($daysUntil) . ' days ago',
            $daysUntil === 0 => 'Today',
            $daysUntil === 1 => 'Tomorrow',
            default => 'In ' . $daysUntil . ' days',
        };
    }

    private function dashboardUrgency(int $daysUntil): string
    {
        return match (true) {
            $daysUntil <= 2 => 'red',
            $daysUntil <= 7 => 'yellow',
            $daysUntil <= 12 => 'green',
            default => 'blue',
        };
    }

    private function dashboardNotificationMessage(array $event): string
    {
        $label = match ($event['type']) {
            'departure' => 'Departure',
            'checkin' => 'Check-in',
            'checkout' => 'Check-out',
            default => 'Update',
        };

        return $label . ' ' . strtolower($event['relative_label']) . ' for ' . ($event['customer_name'] ?: $event['order_number']);
    }

    private function profitRow(
        Order $order,
        string $groupBy,
        ?int $groupId,
        string $groupName,
        string $date,
        string $currency,
        float $revenue,
        float $cost
    ): array {
        $departureDate = $this->voucherDates($order, 'flights', 'date');
        $checkinDate = $this->voucherDates($order, 'hotels', 'check_in');

        return [
            'key' => $groupBy . '-' . ($groupId ?? 0) . '-' . $order->id . '-' . $currency,
            'group_id' => $groupId ?? 0,
            'group_name' => $groupName,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'voucher_no' => $order->voucher_no,
            'booking_reference' => $order->booking_reference,
            'date' => $date,
            'creation_date' => $order->created_at?->toDateString(),
            'invoice_date' => $order->invoice?->invoice_date?->toDateString(),
            'departure_date' => $departureDate,
            'checkin_date' => $checkinDate,
            'service_date' => $this->joinDateValues($departureDate, $checkinDate),
            'status' => $order->status,
            'customer_name' => $order->customer?->name,
            'vendor_name' => $order->vendor?->name,
            'staff_name' => $order->createdBy?->name,
            'currency_code' => $currency,
            'revenue' => round($revenue, 4),
            'cost' => round($cost, 4),
            'profit' => round($revenue - $cost, 4),
            'profit_margin' => $revenue > 0 ? round((($revenue - $cost) / $revenue) * 100, 2) : 0,
        ];
    }

    private function voucherCostTotal(Order $order): float
    {
        $meta = is_array($order->meta) ? $order->meta : [];
        $total = collect($meta['pricing'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->sum(fn (array $row) => OrderVendorCost::toAmount($row['flight_cost'] ?? null));

        foreach (array_keys(OrderVendorCost::SERVICE_SECTIONS) as $section) {
            $total += collect($meta[$section] ?? [])
                ->filter(fn ($row) => is_array($row))
                ->sum(fn (array $row) => OrderVendorCost::amountFromServiceRow($row));
        }

        return (float) $total;
    }

    private function voucherDates(Order $order, string $section, string $field): string
    {
        return collect($this->voucherDateList($order, $section, $field))->join(' / ');
    }

    private function joinDateValues(string ...$values): string
    {
        return collect($values)
            ->flatMap(fn ($value) => explode(' / ', $value))
            ->map(fn ($value) => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->join(' / ');
    }

    private function orderMatchesVoucherDateRange(Order $order, string $dateBy, string $fromDate, string $toDate): bool
    {
        $dates = match ($dateBy) {
            'departure' => $this->voucherDateList($order, 'flights', 'date'),
            'checkin' => $this->voucherDateList($order, 'hotels', 'check_in'),
            'service' => collect([
                ...$this->voucherDateList($order, 'flights', 'date'),
                ...$this->voucherDateList($order, 'hotels', 'check_in'),
            ])->unique()->values()->all(),
            default => [],
        };

        if (!$dates) {
            return false;
        }

        $from = Carbon::parse($fromDate)->startOfDay();
        $to = Carbon::parse($toDate)->endOfDay();

        return collect($dates)->contains(function (string $date) use ($from, $to): bool {
            try {
                return Carbon::parse($date)->betweenIncluded($from, $to);
            } catch (\Throwable) {
                return false;
            }
        });
    }

    private function voucherDateList(Order $order, string $section, string $field): array
    {
        $rows = $order->meta[$section] ?? [];

        if (!is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->map(fn ($row) => is_array($row) ? ($row[$field] ?? null) : null)
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->filter()
            ->map(function ($value) {
                try {
                    return Carbon::parse($value)->toDateString();
                } catch (\Throwable) {
                    return (string) $value;
                }
            })
            ->unique()
            ->values()
            ->all();
    }
}
