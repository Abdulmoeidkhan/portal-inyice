<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\OrderVendorCost;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class StatementService
{
    private const CONFIRMED_REFUND_ORDER_STATUSES = ['partial_refund', 'refund'];

    /**
     * Generate customer statement
     */
    public function customerStatement(
        int $tenantId,
        int $companyId,
        ?int $customerId,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        $customer = $customerId
            ? Customer::where('tenant_id', $tenantId)->where('company_id', $companyId)->findOrFail($customerId)
            : null;

        $baseCurrency = $customer?->company?->base_currency_code
            ?? Company::query()->where('tenant_id', $tenantId)->findOrFail($companyId)->base_currency_code;
        $customCurrency = $customer?->currency_code ?? $baseCurrency;
        $isAllCustomers = $customerId === null;

        $fromDate = $fromDate ? Carbon::parse($fromDate)->toDateString() : null;
        $toDate = $toDate ? Carbon::parse($toDate)->toDateString() : null;

        $invoices = Invoice::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->whereNotIn('status', ['void', 'cancel'])
            ->whereHas('order')
            ->with(['customer:id,name', 'order:id,status'])
            ->when($fromDate, fn($q) => $q->whereDate('invoice_date', '>=', $fromDate))
            ->when($toDate, fn($q) => $q->whereDate('invoice_date', '<=', $toDate))
            ->orderBy('invoice_date')
            ->get();

        $baseCurrencyInvoices = collect([]);
        $customCurrencyInvoices = collect([]);

        foreach ($invoices as $invoice) {
            $record = [
                'invoice_uid' => $invoice->uid,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date,
                'due_date' => $invoice->due_date,
                'customer_name' => $invoice->customer?->name,
                'status' => $this->invoiceActivityStatus($invoice),
                'amount' => $invoice->total_amount,
                'currency' => $invoice->currency_code,
                'outstanding' => $invoice->outstanding_amount,
                'advance' => $invoice->advance_balance,
            ];

            // Keep in base currency for internal report
            $baseCurrencyInvoices->push($record);

            // Convert to customer preferred currency for external statement
            if (!$isAllCustomers && $invoice->currency_code !== $customCurrency) {
                $fxRate = ExchangeRate::getRate(
                    $tenantId,
                    $invoice->currency_code,
                    $customCurrency,
                    Carbon::parse($invoice->invoice_date)
                ) ?? 1;

                $record['amount_in_client_currency'] = (float)$invoice->total_amount * $fxRate;
                $record['fx_rate'] = $fxRate;
            } else {
                $record['amount_in_client_currency'] = (float)$invoice->total_amount;
            }

            $customCurrencyInvoices->push($record);
        }

        $cashTransactions = collect();
        foreach ($invoices as $invoice) {
            $amount = (float) $invoice->total_amount;
            if ($amount < 0) {
                $cashTransactions->push(['id' => 'invoice-' . $invoice->id, 'date' => $invoice->invoice_date->toDateString(), 'type' => 'refund', 'reference' => $invoice->invoice_number, 'customer_name' => $invoice->customer?->name, 'description' => 'Customer refund invoice', 'sales' => 0, 'refunds' => abs($amount), 'customer_receipts' => 0, 'customer_payments' => 0, 'debit' => 0, 'credit' => abs($amount), 'sort_order' => 2]);
                continue;
            }

            $cashTransactions->push(['id' => 'invoice-' . $invoice->id, 'date' => $invoice->invoice_date->toDateString(), 'type' => 'invoice', 'reference' => $invoice->invoice_number, 'customer_name' => $invoice->customer?->name, 'description' => 'Customer invoice', 'sales' => $amount, 'refunds' => 0, 'customer_receipts' => 0, 'customer_payments' => 0, 'debit' => $amount, 'credit' => 0, 'sort_order' => 1]);
        }
        Order::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->whereIn('status', self::CONFIRMED_REFUND_ORDER_STATUSES)
            ->where('total_amount', '<', 0)
            ->whereDoesntHave('invoice', fn ($invoice) => $invoice->whereNotIn('status', ['void', 'cancel']))
            ->with('customer:id,name')
            ->when($fromDate, fn ($q) => $q->whereDate('created_at', '>=', $fromDate))->when($toDate, fn ($q) => $q->whereDate('created_at', '<=', $toDate))->get()
            ->each(function (Order $order) use ($cashTransactions): void {
                $amount = abs((float) $order->total_amount);
                $cashTransactions->push(['id' => 'refund-order-' . $order->id, 'date' => $order->created_at->toDateString(), 'type' => 'refund', 'reference' => $order->order_number, 'customer_name' => $order->customer?->name, 'description' => $order->notes ?: 'Customer refund request', 'sales' => 0, 'refunds' => $amount, 'customer_receipts' => 0, 'customer_payments' => 0, 'debit' => 0, 'credit' => $amount, 'sort_order' => 2]);
            });
        Receipt::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->with('customer:id,name')
            ->when($fromDate, fn ($q) => $q->whereDate('receipt_date', '>=', $fromDate))->when($toDate, fn ($q) => $q->whereDate('receipt_date', '<=', $toDate))->get()
            ->each(fn (Receipt $receipt) => $cashTransactions->push(['id' => 'receipt-' . $receipt->id, 'date' => $receipt->receipt_date->toDateString(), 'type' => 'receipt', 'reference' => $receipt->receipt_number, 'customer_name' => $receipt->customer?->name, 'description' => $receipt->description ?: 'Receipt from customer', 'sales' => 0, 'refunds' => 0, 'customer_receipts' => (float) $receipt->amount, 'customer_payments' => 0, 'debit' => 0, 'credit' => (float) $receipt->amount, 'sort_order' => 3]));
        Payment::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->with('customer:id,name')
            ->when($fromDate, fn ($q) => $q->whereDate('payment_date', '>=', $fromDate))->when($toDate, fn ($q) => $q->whereDate('payment_date', '<=', $toDate))->get()
            ->each(fn (Payment $payment) => $cashTransactions->push(['id' => 'payment-' . $payment->id, 'date' => $payment->payment_date->toDateString(), 'type' => 'payment', 'reference' => $payment->payment_number, 'customer_name' => $payment->customer?->name, 'description' => $payment->description ?: 'Payment to customer', 'sales' => 0, 'refunds' => 0, 'customer_receipts' => 0, 'customer_payments' => (float) $payment->amount, 'debit' => (float) $payment->amount, 'credit' => 0, 'sort_order' => 4]));
        $runningCustomerBalance = 0;
        $cashTransactions = $cashTransactions->sortBy([['date', 'asc'], ['sort_order', 'asc']])->values()->map(function ($row) use (&$runningCustomerBalance) {
            $runningCustomerBalance += $row['debit'] - $row['credit']; $row['balance'] = $runningCustomerBalance; unset($row['sort_order']); return $row;
        });
        $totalAmount = (float) $customCurrencyInvoices->sum('amount_in_client_currency');
        $totalReceipts = (float) $cashTransactions->sum('customer_receipts');
        $totalPayments = (float) $cashTransactions->sum('customer_payments');
        $statementPaid = min($totalAmount, max(0, $totalReceipts - $totalPayments));

        return [
            'customer' => [
                'uid' => $customer?->uid,
                'name' => $customer?->name ?? 'All Customers',
                'email' => $customer?->email,
                'phone' => $customer?->phone,
                'billing_address' => $customer?->address,
                'is_all' => $isAllCustomers,
            ],
            'statement_date' => now()->toDateString(),
            'period' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'base_currency' => $baseCurrency,
            'customer_currency' => $customCurrency,
            'base_currency_invoices' => $baseCurrencyInvoices->toArray(),
            'customer_currency_invoices' => $customCurrencyInvoices->toArray(),
            'transactions' => $cashTransactions->all(),
            'summary' => [
                'total_invoices' => count($invoices),
                'total_outstanding' => round($runningCustomerBalance, 4),
                'total_paid' => round($statementPaid, 4),
                'total_receipts' => round($totalReceipts, 4),
                'total_payments' => round($totalPayments, 4),
                'total_amount' => round($totalAmount, 4),
            ],
        ];
    }

    /**
     * Generate vendor statement
     */
    public function vendorStatement(
        int $tenantId,
        int $companyId,
        int $vendorId,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        $vendor = Vendor::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($vendorId);

        $baseCurrency = $vendor->company->base_currency_code;

        $fromDate = $fromDate ? Carbon::parse($fromDate)->toDateString() : null;
        $toDate = $toDate ? Carbon::parse($toDate)->toDateString() : null;

        $eligibleOrders = Order::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where(function ($query): void {
                $query->whereHas('invoice', fn ($invoice) => $invoice->whereNotIn('status', ['void', 'cancel']))
                    ->orWhereIn('status', self::CONFIRMED_REFUND_ORDER_STATUSES);
            })
            ->with(['customer:id,name', 'invoice:id,order_id,invoice_date', 'vendorCosts'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Order $order) => $this->vendorPayableAmount($order, $vendorId) != 0.0);
        $paymentsQuery = Payment::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('vendor_id', $vendorId);
        $receiptsQuery = Receipt::where('tenant_id', $tenantId)->where('company_id', $companyId)->where('vendor_id', $vendorId);

        $openingPayables = $eligibleOrders
            ->when($fromDate, fn ($orders) => $orders->filter(fn (Order $order) => $order->invoice?->invoice_date?->toDateString() < $fromDate))
            ->sum(fn (Order $order) => $this->vendorPayableAmount($order, $vendorId));
        $openingPayments = (clone $paymentsQuery)
            ->when($fromDate, fn ($query) => $query->whereDate('payment_date', '<', $fromDate))
            ->sum('amount');
        $openingReceipts = (clone $receiptsQuery)->when($fromDate, fn ($query) => $query->whereDate('receipt_date', '<', $fromDate))->sum('amount');
        $openingBalance = $fromDate ? (float) $openingPayables - (float) $openingPayments + (float) $openingReceipts : 0;

        $orders = $eligibleOrders
            ->when($fromDate, fn ($items) => $items->filter(fn (Order $order) => $order->invoice?->invoice_date?->toDateString() >= $fromDate))
            ->when($toDate, fn ($items) => $items->filter(fn (Order $order) => $order->invoice?->invoice_date?->toDateString() <= $toDate));
        $payments = $paymentsQuery
            ->when($fromDate, fn ($query) => $query->whereDate('payment_date', '>=', $fromDate))
            ->when($toDate, fn ($query) => $query->whereDate('payment_date', '<=', $toDate))
            ->orderBy('payment_date')
            ->get();
        $receipts = $receiptsQuery->when($fromDate, fn ($q) => $q->whereDate('receipt_date', '>=', $fromDate))->when($toDate, fn ($q) => $q->whereDate('receipt_date', '<=', $toDate))->get();

        $transactions = collect();
        foreach ($orders as $order) {
            $payable = $this->vendorPayableAmount($order, $vendorId);
            $purchaseSummary = $this->vendorPurchaseSummary($order, $vendorId, $payable);

            foreach ($purchaseSummary['rows'] as $index => $purchaseRow) {
                $amount = (float) ($purchaseRow['amount'] ?? 0);
                if ($amount == 0.0) {
                    continue;
                }

                $isRefund = $amount < 0;
                $transactions->push([
                    'id' => 'order-' . $order->id . '-' . ($purchaseRow['key'] ?? $index),
                    'date' => $order->invoice?->invoice_date?->toDateString() ?? $order->created_at->toDateString(),
                    'type' => $isRefund ? 'vendor_refund' : 'payable',
                    'reference' => $order->order_number,
                    'order_number' => $order->order_number,
                    'reference_url' => '/orders/' . $order->uid . '/edit',
                    'service' => $purchaseRow['service'] ?? 'SERVICE',
                    'description' => $purchaseRow['details'] ?? $purchaseSummary['narration'],
                    'vendor_payables' => $isRefund ? 0 : $amount,
                    'vendor_refunds' => $isRefund ? abs($amount) : 0,
                    'vendor_payments' => 0,
                    'vendor_receipts' => 0,
                    'debit' => $isRefund ? 0 : $amount,
                    'credit' => $isRefund ? abs($amount) : 0,
                    'sort_order' => 1,
                ]);
            }
        }
        foreach ($payments as $payment) {
            $transactions->push([
                'id' => 'payment-' . $payment->id,
                'date' => $payment->payment_date->toDateString(),
                'type' => 'payment',
                'reference' => $payment->payment_number,
                'order_number' => null,
                'service' => null,
                'description' => $payment->description ?: 'Payment to vendor',
                'vendor_payables' => 0,
                'vendor_refunds' => 0,
                'vendor_payments' => (float) $payment->amount,
                'vendor_receipts' => 0,
                'debit' => 0,
                'credit' => (float) $payment->amount,
                'sort_order' => 2,
            ]);
        }
        foreach ($receipts as $receipt) {
            $transactions->push([
                'id' => 'receipt-' . $receipt->id, 'date' => $receipt->receipt_date->toDateString(), 'type' => 'receipt',
                'reference' => $receipt->receipt_number, 'order_number' => null, 'service' => null, 'description' => $receipt->description ?: 'Receipt from vendor',
                'vendor_payables' => 0, 'vendor_refunds' => 0, 'vendor_payments' => 0, 'vendor_receipts' => (float) $receipt->amount,
                'debit' => (float) $receipt->amount, 'credit' => 0, 'sort_order' => 3,
            ]);
        }

        $runningBalance = $openingBalance;
        $transactions = $transactions
            ->sortBy([['date', 'asc'], ['sort_order', 'asc']])
            ->values()
            ->map(function (array $transaction) use (&$runningBalance): array {
                $runningBalance += (float) $transaction['debit'] - (float) $transaction['credit'];
                $transaction['balance'] = $runningBalance;
                unset($transaction['sort_order']);
                return $transaction;
            });

        $periodPayables = $transactions->sum('vendor_payables');
        $periodRefunds = $transactions->sum('vendor_refunds');
        $periodPayments = $transactions->sum('vendor_payments');
        $periodReceipts = $transactions->sum('vendor_receipts');

        return [
            'vendor' => [
                'uid' => $vendor->uid,
                'name' => $vendor->name,
                'email' => $vendor->email,
                'phone' => $vendor->phone,
                'billing_address' => $vendor->address,
            ],
            'statement_date' => now()->toDateString(),
            'period' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'base_currency' => $baseCurrency,
            'vendor_currency' => $vendor->currency_code ?: $baseCurrency,
            'transactions' => $transactions->toArray(),
            'summary' => [
                'opening_balance' => $openingBalance,
                'period_payables' => $periodPayables,
                'period_refunds' => $periodRefunds,
                'period_paid' => $periodPayments,
                'period_payments' => $periodPayments,
                'period_receipts' => $periodReceipts,
                'outstanding_balance' => $runningBalance,
            ],
        ];
    }

    /**
     * Show refund invoices with their refund lifecycle status in statement activity.
     */
    private function invoiceActivityStatus(Invoice $invoice): string
    {
        $orderStatus = (string) $invoice->order?->status;

        if ((float) $invoice->total_amount < 0 && in_array($orderStatus, self::CONFIRMED_REFUND_ORDER_STATUSES, true)) {
            return $orderStatus;
        }

        return (string) $invoice->status;
    }

    /**
     * Calculate the vendor-side cost stored in voucher metadata.
     * Legacy orders without cost detail fall back to the order total.
     */
    public function vendorPayableAmount(Order $order, int $vendorId): float
    {
        return $order->vendorPayableAmountFor($vendorId);
    }

    private function vendorPurchaseSummary(Order $order, int $vendorId, float $payable): array
    {
        $customerName = trim((string) ($order->customer?->name ?: 'Walk-in Customer'));
        $passengerDetails = $this->voucherPassengerDetails($order);
        $passengerCount = count($passengerDetails) ?: $this->voucherPassengerCount($order);
        $serviceRows = $this->vendorPurchaseRows($order, $vendorId, $payable);
        $services = collect($serviceRows)
            ->pluck('service')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($services === []) {
            $services = ['SERVICE'];
        }

        $passengerLabel = $passengerCount === 1 ? '1 passenger' : $passengerCount . ' passengers';

        return [
            'order_uid' => $order->uid,
            'order_number' => $order->order_number,
            'booking_reference' => $order->booking_reference,
            'customer_name' => $customerName,
            'passenger_count' => $passengerCount,
            'services' => $services,
            'currency_code' => $order->currency_code,
            'total_purchase_amount' => round($payable, 4),
            'narration' => sprintf('%s (%s), %s', $customerName, $passengerLabel, implode(', ', $services)),
            'passenger_details' => $passengerDetails,
            'rows' => $serviceRows,
        ];
    }

    private function voucherPassengerDetails(Order $order): array
    {
        $meta = is_array($order->meta) ? $order->meta : [];
        $passengers = collect($meta['passengers'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->values();
        $pricingRows = collect($meta['pricing'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->values();
        $visaRows = collect($meta['visa'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->values();
        $rows = collect();

        foreach ($passengers as $index => $passenger) {
            $name = $this->firstFilled(
                $passenger['name'] ?? null,
                $pricingRows[$index]['pax_name'] ?? null,
                $visaRows[$index]['passenger_name'] ?? null,
                'Passenger ' . ($index + 1)
            );
            $pricing = $this->voucherPassengerRelatedRow($pricingRows, 'pax_name', $name, $index);
            $visa = $this->voucherPassengerRelatedRow($visaRows, 'passenger_name', $name, $index);

            $rows->push($this->voucherPassengerDetailRow($index, $name, $passenger, $pricing, $visa));
        }

        foreach ($pricingRows as $index => $pricing) {
            $name = $this->firstFilled($pricing['pax_name'] ?? null, 'Passenger ' . ($index + 1));
            if ($this->hasPassengerDetailRow($rows, $name)) {
                continue;
            }

            $visa = $this->voucherPassengerRelatedRow($visaRows, 'passenger_name', $name, $index);
            $rows->push($this->voucherPassengerDetailRow($rows->count(), $name, [], $pricing, $visa));
        }

        foreach ($visaRows as $index => $visa) {
            $name = $this->firstFilled($visa['passenger_name'] ?? null, 'Passenger ' . ($index + 1));
            if ($this->hasPassengerDetailRow($rows, $name)) {
                continue;
            }

            $rows->push($this->voucherPassengerDetailRow($rows->count(), $name, [], [], $visa));
        }

        return $rows->values()->all();
    }

    private function voucherPassengerCount(Order $order): int
    {
        $meta = is_array($order->meta) ? $order->meta : [];
        $passengerNames = collect($meta['passengers'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => trim((string) ($row['name'] ?? '')))
            ->filter()
            ->unique()
            ->values();

        if ($passengerNames->isNotEmpty()) {
            return $passengerNames->count();
        }

        $fallbackNames = collect($meta['pricing'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => trim((string) ($row['pax_name'] ?? '')))
            ->merge(collect($meta['visa'] ?? [])
                ->filter(fn ($row) => is_array($row))
                ->map(fn (array $row) => trim((string) ($row['passenger_name'] ?? ''))))
            ->filter()
            ->unique()
            ->values();

        return max(1, $fallbackNames->count());
    }

    private function voucherPassengerRelatedRow(Collection $rows, string $nameField, string $name, int $index): array
    {
        $matched = $rows->first(fn (array $row) => strcasecmp(trim((string) ($row[$nameField] ?? '')), $name) === 0);

        if (is_array($matched)) {
            return $matched;
        }

        $fallback = $rows[$index] ?? [];

        return is_array($fallback) ? $fallback : [];
    }

    private function voucherPassengerDetailRow(int $index, string $name, array $passenger, array $pricing, array $visa): array
    {
        return [
            'key' => 'passenger-' . $index,
            'name' => $name,
            'passport_no' => $this->firstFilled($passenger['passport_no'] ?? null, $passenger['passport_number'] ?? null),
            'ticket_no' => $this->firstFilled($passenger['ticket_no'] ?? null, $pricing['flight_ticket_no'] ?? null),
            'visa_publisher' => $this->firstFilled($passenger['visa_publisher'] ?? null, $visa['visa_publisher'] ?? null, $visa['visa_vendor'] ?? null),
            'visa_no' => $this->firstFilled($passenger['visa_no'] ?? null, $visa['visa_no'] ?? null),
            'notes' => $this->firstFilled($passenger['notes'] ?? null, $visa['notes'] ?? null),
        ];
    }

    private function hasPassengerDetailRow(Collection $rows, string $name): bool
    {
        return $rows->contains(fn (array $row) => strcasecmp((string) ($row['name'] ?? ''), $name) === 0);
    }

    private function vendorPurchaseRows(Order $order, int $vendorId, float $payable): array
    {
        $meta = is_array($order->meta) ? $order->meta : [];
        $costRows = $order->relationLoaded('vendorCosts')
            ? $order->vendorCosts->where('vendor_id', $vendorId)->values()
            : $order->vendorCosts()->where('vendor_id', $vendorId)->get();

        if ($costRows->isNotEmpty()) {
            return $this->sortVendorPurchaseRows($costRows
                ->map(fn (OrderVendorCost $cost): array => $this->vendorPurchaseRowFromCost($cost, $meta, $order))
                ->values()
                ->all());
        }

        $rows = $this->vendorPurchaseRowsFromVoucherMeta($meta, $vendorId, $order);

        if ($rows !== []) {
            return $this->sortVendorPurchaseRows($rows);
        }

        return [[
            'key' => 'order-total',
            'service' => 'SERVICE',
            'passenger' => $this->firstFilled($meta['passengers'][0]['name'] ?? null, $meta['pricing'][0]['pax_name'] ?? null, 'Unassigned'),
            'details' => $order->notes ?: 'Order purchase',
            'amount' => round($payable, 4),
        ]];
    }

    private function vendorPurchaseRowFromCost(OrderVendorCost $cost, array $meta, Order $order): array
    {
        $service = $this->statementServiceName($cost->service_type);
        $source = $this->voucherServiceSourceRow($meta, $cost->service_type, (int) $cost->service_index);

        return [
            'key' => $cost->service_type . '-' . $cost->service_index . '-' . $cost->id,
            'service' => $service,
            'passenger' => $this->purchasePassengerName($cost->service_type, $source, $meta),
            'details' => $this->purchaseDetails($cost->service_type, $source, $meta, $order),
            'amount' => round((float) $cost->amount, 4),
        ];
    }

    private function vendorPurchaseRowsFromVoucherMeta(array $meta, int $vendorId, Order $order): array
    {
        $rows = [];

        foreach (($meta['pricing'] ?? []) as $index => $pricing) {
            if (!is_array($pricing) || (int) ($pricing['vendor_id'] ?? 0) !== $vendorId) {
                continue;
            }

            $amount = OrderVendorCost::toAmount($pricing['flight_cost'] ?? null);
            if ($amount != 0.0) {
                $rows[] = [
                    'key' => 'flight-' . $index,
                    'service' => 'FLIGHT',
                    'passenger' => $this->purchasePassengerName('flight', $pricing, $meta),
                    'details' => $this->purchaseDetails('flight', $pricing, $meta, $order),
                    'amount' => round($amount, 4),
                ];
            }
        }

        foreach (OrderVendorCost::SERVICE_SECTIONS as $section => $serviceType) {
            foreach (($meta[$section] ?? []) as $index => $serviceRow) {
                if (!is_array($serviceRow) || (int) ($serviceRow['vendor_id'] ?? 0) !== $vendorId) {
                    continue;
                }

                $amount = OrderVendorCost::amountFromServiceRow($serviceRow);
                if ($amount != 0.0) {
                    $rows[] = [
                        'key' => $serviceType . '-' . $index,
                        'service' => $this->statementServiceName($serviceType),
                        'passenger' => $this->purchasePassengerName($serviceType, $serviceRow, $meta),
                        'details' => $this->purchaseDetails($serviceType, $serviceRow, $meta, $order),
                        'amount' => round($amount, 4),
                    ];
                }
            }
        }

        return $rows;
    }

    private function voucherServiceSourceRow(array $meta, string $serviceType, int $index): array
    {
        $section = match ($serviceType) {
            'flight' => 'pricing',
            'hotel' => 'hotels',
            'transfer' => 'transfers',
            'city_tour' => 'city_tours',
            'visa' => 'visa',
            'other_service' => 'other_services',
            default => null,
        };

        if ($section === null) {
            return [];
        }

        $row = $meta[$section][$index] ?? [];

        return is_array($row) ? $row : [];
    }

    private function statementServiceName(string $serviceType): string
    {
        return match ($serviceType) {
            'flight' => 'FLIGHT',
            'hotel' => 'HOTEL',
            'visa' => 'VISA',
            'transfer', 'city_tour' => 'TRANSPORT',
            default => 'SERVICE',
        };
    }

    private function sortVendorPurchaseRows(array $rows): array
    {
        $priority = [
            'FLIGHT' => 10,
            'HOTEL' => 20,
            'VISA' => 30,
            'TRANSPORT' => 40,
            'SERVICE' => 50,
        ];

        return collect($rows)
            ->sortBy(fn (array $row) => sprintf(
                '%02d-%s',
                $priority[$row['service'] ?? 'SERVICE'] ?? 90,
                $row['key'] ?? ''
            ))
            ->values()
            ->all();
    }

    private function purchasePassengerName(string $serviceType, array $row, array $meta): string
    {
        $firstPassenger = $this->firstFilled(
            $meta['passengers'][0]['name'] ?? null,
            $meta['pricing'][0]['pax_name'] ?? null,
            $meta['visa'][0]['passenger_name'] ?? null,
            'Unassigned'
        );

        return $this->firstFilled(
            match ($serviceType) {
                'flight' => $row['pax_name'] ?? null,
                'visa' => $row['passenger_name'] ?? null,
                'hotel' => $row['lead_passenger'] ?? null,
                default => null,
            },
            $firstPassenger
        );
    }

    private function purchaseDetails(string $serviceType, array $row, array $meta, Order $order): string
    {
        return match ($serviceType) {
            'flight' => $this->flightPurchaseDetails($row, $meta, $order),
            'hotel' => $this->hotelPurchaseDetails($row),
            'visa' => $this->visaPurchaseDetails($row, $meta),
            'transfer' => $this->transferPurchaseDetails($row, $meta),
            'city_tour' => $this->cityTourPurchaseDetails($row, $meta),
            'other_service' => $this->firstFilled($row['description'] ?? null, $row['notes'] ?? null, 'Service purchase'),
            default => 'Order purchase',
        };
    }

    private function flightPurchaseDetails(array $row, array $meta, Order $order): string
    {
        $firstSector = collect($meta['flights'] ?? [])->first(fn ($flight) => is_array($flight)) ?: [];
        $passenger = $this->purchasePassengerName('flight', $row, $meta);
        $pnr = $this->firstFilled($row['pnr'] ?? null, $row['gds_pnr'] ?? null, $order->booking_reference, $meta['booking_reference'] ?? null);

        return $this->joinFilled([
            $this->labeledValue('Passenger', $passenger),
            $this->labeledValue('PNR', $pnr),
            $this->labeledValue('Departure', $firstSector['date'] ?? null),
            $this->labeledValue('Flight', $firstSector['flight_no'] ?? null),
        ], ' | ') ?: 'Flight purchase';
    }

    private function hotelPurchaseDetails(array $row): string
    {
        return $this->joinFilled([
            $this->labeledValue('Hotel', $row['hotel_name'] ?? null),
            $this->labeledValue('Check-in', $row['check_in'] ?? null),
            $this->labeledValue('Check-out', $row['check_out'] ?? null),
            $this->labeledValue('Nights', $this->hotelNightCount($row['check_in'] ?? null, $row['check_out'] ?? null)),
            $this->labeledValue('City', $row['city'] ?? null),
        ], ' | ') ?: 'Hotel purchase';
    }

    private function visaPurchaseDetails(array $row, array $meta): string
    {
        return $this->joinFilled([
            $this->labeledValue('Visa No', $this->firstFilled($row['visa_no'] ?? null, $row['visa_number'] ?? null)),
            $this->labeledValue('Passenger', $this->purchasePassengerName('visa', $row, $meta)),
        ], ' | ') ?: 'Visa purchase';
    }

    private function transferPurchaseDetails(array $row, array $meta): string
    {
        $journey = $this->joinFilled([$row['from_city'] ?? null, $row['to_city'] ?? null], ' to ');

        return $this->joinFilled([
            $this->labeledValue('Journey', $this->firstFilled($journey, $row['service'] ?? null)),
            $this->labeledValue('Car', $this->firstFilled($row['vehicle'] ?? null, $row['car_name'] ?? null, $row['car'] ?? null)),
            $this->labeledValue('Passenger', $this->purchasePassengerName('transfer', $row, $meta)),
        ], ' | ') ?: 'Transport purchase';
    }

    private function cityTourPurchaseDetails(array $row, array $meta): string
    {
        return $this->joinFilled([
            $this->labeledValue('Journey', $this->joinFilled([$row['title'] ?? null, $row['city'] ?? null], ' - ')),
            $this->labeledValue('Car', $this->firstFilled($row['vehicle'] ?? null, $row['car_name'] ?? null, $row['car'] ?? null)),
            $this->labeledValue('Passenger', $this->purchasePassengerName('city_tour', $row, $meta)),
        ], ' | ') ?: 'Transport purchase';
    }

    private function labeledValue(string $label, mixed $value): string
    {
        $value = $this->firstFilled($value);

        return $value === '' ? '' : $label . ': ' . $value;
    }

    private function hotelNightCount(mixed $checkIn, mixed $checkOut): string
    {
        if ($this->firstFilled($checkIn) === '' || $this->firstFilled($checkOut) === '') {
            return '';
        }

        try {
            $start = Carbon::parse($checkIn)->startOfDay();
            $end = Carbon::parse($checkOut)->startOfDay();
        } catch (\Throwable) {
            return '';
        }

        $nights = (int) $start->diffInDays($end, false);

        return $nights > 0 ? (string) $nights : '';
    }

    private function firstFilled(mixed ...$values): string
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function joinFilled(array $values, string $separator): string
    {
        return collect($values)
            ->map(fn ($value) => $value === null ? '' : trim((string) $value))
            ->filter()
            ->implode($separator);
    }
}
