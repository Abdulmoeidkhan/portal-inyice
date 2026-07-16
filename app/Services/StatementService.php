<?php

namespace App\Services;

use App\Models\Customer;
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
        int $customerId,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        $customer = Customer::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($customerId);

        $baseCurrency = $customer->company->base_currency_code;
        $customCurrency = $customer->currency_code ?? $baseCurrency;

        $fromDate = $fromDate ? Carbon::parse($fromDate)->toDateString() : null;
        $toDate = $toDate ? Carbon::parse($toDate)->toDateString() : null;

        $invoices = Invoice::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->where('status', '!=', 'void')
            ->whereHas('order')
            ->when($fromDate, fn($q) => $q->where('invoice_date', '>=', $fromDate))
            ->when($toDate, fn($q) => $q->where('invoice_date', '<=', $toDate))
            ->orderBy('invoice_date')
            ->get();

        $baseCurrencyInvoices = collect([]);
        $customCurrencyInvoices = collect([]);
        $totalOutstanding = 0;
        $totalPaid = 0;

        foreach ($invoices as $invoice) {
            $record = [
                'invoice_uid' => $invoice->uid,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date,
                'due_date' => $invoice->due_date,
                'status' => $invoice->status,
                'amount' => $invoice->total_amount,
                'currency' => $invoice->currency_code,
                'outstanding' => $invoice->outstanding_amount,
                'advance' => $invoice->advance_balance,
            ];

            // Keep in base currency for internal report
            $baseCurrencyInvoices->push($record);

            // Convert to customer preferred currency for external statement
            if ($invoice->currency_code !== $customCurrency) {
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
            $totalOutstanding += $invoice->outstanding_amount;
            $totalPaid += ($invoice->total_amount - $invoice->outstanding_amount);
        }

        $cashTransactions = collect();
        foreach ($invoices as $invoice) {
            $cashTransactions->push(['id' => 'invoice-' . $invoice->id, 'date' => $invoice->invoice_date->toDateString(), 'type' => 'invoice', 'reference' => $invoice->invoice_number, 'description' => 'Customer invoice', 'debit' => (float) $invoice->total_amount, 'credit' => 0, 'sort_order' => 1]);
        }
        Receipt::where('tenant_id', $tenantId)->where('customer_id', $customerId)
            ->where('company_id', $companyId)
            ->when($fromDate, fn ($q) => $q->where('receipt_date', '>=', $fromDate))->when($toDate, fn ($q) => $q->where('receipt_date', '<=', $toDate))->get()
            ->each(fn (Receipt $receipt) => $cashTransactions->push(['id' => 'receipt-' . $receipt->id, 'date' => $receipt->receipt_date->toDateString(), 'type' => 'receipt', 'reference' => $receipt->receipt_number, 'description' => $receipt->description ?: 'Receipt from customer', 'debit' => 0, 'credit' => (float) $receipt->amount, 'sort_order' => 2]));
        Payment::where('tenant_id', $tenantId)->where('customer_id', $customerId)
            ->where('company_id', $companyId)
            ->when($fromDate, fn ($q) => $q->where('payment_date', '>=', $fromDate))->when($toDate, fn ($q) => $q->where('payment_date', '<=', $toDate))->get()
            ->each(fn (Payment $payment) => $cashTransactions->push(['id' => 'payment-' . $payment->id, 'date' => $payment->payment_date->toDateString(), 'type' => 'payment', 'reference' => $payment->payment_number, 'description' => $payment->description ?: 'Payment to customer', 'debit' => (float) $payment->amount, 'credit' => 0, 'sort_order' => 3]));
        $runningCustomerBalance = 0;
        $cashTransactions = $cashTransactions->sortBy([['date', 'asc'], ['sort_order', 'asc']])->values()->map(function ($row) use (&$runningCustomerBalance) {
            $runningCustomerBalance += $row['debit'] - $row['credit']; $row['balance'] = $runningCustomerBalance; unset($row['sort_order']); return $row;
        });

        return [
            'customer' => [
                'uid' => $customer->uid,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'billing_address' => $customer->address,
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
                'total_outstanding' => $totalOutstanding,
                'total_paid' => $totalPaid,
                'total_amount' => $totalOutstanding + $totalPaid,
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
            ->whereHas('invoice', fn ($invoice) => $invoice->where('status', '!=', 'void'))
            ->with('invoice:id,order_id,invoice_date')
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Order $order) => $this->vendorPayableAmount($order, $vendorId) > 0);
        $paymentsQuery = Payment::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('vendor_id', $vendorId);
        $receiptsQuery = Receipt::where('tenant_id', $tenantId)->where('company_id', $companyId)->where('vendor_id', $vendorId);

        $openingPayables = $eligibleOrders
            ->when($fromDate, fn ($orders) => $orders->filter(fn (Order $order) => $order->invoice?->invoice_date?->toDateString() < $fromDate))
            ->sum(fn (Order $order) => $this->vendorPayableAmount($order, $vendorId));
        $openingPayments = (clone $paymentsQuery)
            ->when($fromDate, fn ($query) => $query->where('payment_date', '<', $fromDate))
            ->sum('amount');
        $openingReceipts = (clone $receiptsQuery)->when($fromDate, fn ($query) => $query->where('receipt_date', '<', $fromDate))->sum('amount');
        $openingBalance = $fromDate ? (float) $openingPayables - (float) $openingPayments + (float) $openingReceipts : 0;

        $orders = $eligibleOrders
            ->when($fromDate, fn ($items) => $items->filter(fn (Order $order) => $order->invoice?->invoice_date?->toDateString() >= $fromDate))
            ->when($toDate, fn ($items) => $items->filter(fn (Order $order) => $order->invoice?->invoice_date?->toDateString() <= $toDate));
        $payments = $paymentsQuery
            ->when($fromDate, fn ($query) => $query->where('payment_date', '>=', $fromDate))
            ->when($toDate, fn ($query) => $query->where('payment_date', '<=', $toDate))
            ->orderBy('payment_date')
            ->get();
        $receipts = $receiptsQuery->when($fromDate, fn ($q) => $q->where('receipt_date', '>=', $fromDate))->when($toDate, fn ($q) => $q->where('receipt_date', '<=', $toDate))->get();

        $transactions = collect();
        foreach ($orders as $order) {
            $transactions->push([
                'id' => 'order-' . $order->id,
                'date' => $order->invoice?->invoice_date?->toDateString() ?? $order->created_at->toDateString(),
                'type' => 'payable',
                'reference' => $order->order_number,
                'description' => $order->notes ?: 'Vendor cost for order',
                'debit' => $this->vendorPayableAmount($order, $vendorId),
                'credit' => 0,
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
                'debit' => 0,
                'credit' => (float) $payment->amount,
                'sort_order' => 2,
            ]);
        }
        foreach ($receipts as $receipt) {
            $transactions->push([
                'id' => 'receipt-' . $receipt->id, 'date' => $receipt->receipt_date->toDateString(), 'type' => 'receipt',
                'reference' => $receipt->receipt_number, 'description' => $receipt->description ?: 'Receipt from vendor',
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

        $periodPayables = $transactions->sum('debit');
        $periodPaid = $transactions->sum('credit');

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
                'period_paid' => $periodPaid,
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
