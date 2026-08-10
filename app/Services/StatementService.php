<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class StatementService
{
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
            ->with('customer:id,name')
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
                'status' => $invoice->status,
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
            $cashTransactions->push(['id' => 'invoice-' . $invoice->id, 'date' => $invoice->invoice_date->toDateString(), 'type' => 'invoice', 'reference' => $invoice->invoice_number, 'customer_name' => $invoice->customer?->name, 'description' => 'Customer invoice', 'sales' => $amount, 'refunds' => 0, 'customer_receipts' => 0, 'customer_payments' => 0, 'debit' => $amount, 'credit' => 0, 'sort_order' => 1]);
        }
        Order::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->whereIn('status', ['refund_request', 'partial_refund', 'refund'])
            ->where('total_amount', '<', 0)
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
                    ->orWhereIn('status', ['refund_request', 'partial_refund', 'refund']);
            })
            ->with('invoice:id,order_id,invoice_date')
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
            $isRefund = $payable < 0;
            $transactions->push([
                'id' => 'order-' . $order->id,
                'date' => $order->invoice?->invoice_date?->toDateString() ?? $order->created_at->toDateString(),
                'type' => $isRefund ? 'vendor_refund' : 'payable',
                'reference' => $order->order_number,
                'description' => $order->notes ?: ($isRefund ? 'Vendor refund for order' : 'Vendor cost for order'),
                'vendor_payables' => $isRefund ? 0 : $payable,
                'vendor_refunds' => $isRefund ? abs($payable) : 0,
                'vendor_payments' => 0,
                'vendor_receipts' => 0,
                'debit' => $isRefund ? 0 : $payable,
                'credit' => $isRefund ? abs($payable) : 0,
                'sort_order' => 1,
            ]);
        }
        foreach ($payments as $payment) {
            $transactions->push([
                'id' => 'payment-' . $payment->id,
                'date' => $payment->payment_date->toDateString(),
                'type' => 'payment',
                'reference' => $payment->payment_number,
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
                'reference' => $receipt->receipt_number, 'description' => $receipt->description ?: 'Receipt from vendor',
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
     * Calculate the vendor-side cost stored in voucher metadata.
     * Legacy orders without cost detail fall back to the order total.
     */
    public function vendorPayableAmount(Order $order, int $vendorId): float
    {
        return $order->vendorPayableAmountFor($vendorId);
    }
}
