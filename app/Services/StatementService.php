<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ExchangeRate;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class StatementService
{
    /**
     * Generate customer statement
     */
    public function customerStatement(
        int $tenantId,
        int $customerId,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        $customer = Customer::where('tenant_id', $tenantId)
            ->findOrFail($customerId);

        $baseCurrency = $customer->company->base_currency_code;
        $customCurrency = $customer->currency_code ?? $baseCurrency;

        $fromDate = $fromDate ? Carbon::parse($fromDate)->toDateString() : null;
        $toDate = $toDate ? Carbon::parse($toDate)->toDateString() : null;

        $invoices = Invoice::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
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
        int $vendorId,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        // Similar to customer statement but tracks payables instead of receivables
        $vendor = \App\Models\Vendor::where('tenant_id', $tenantId)
            ->findOrFail($vendorId);

        $baseCurrency = $vendor->company->base_currency_code;

        $fromDate = $fromDate ? Carbon::parse($fromDate)->toDateString() : null;
        $toDate = $toDate ? Carbon::parse($toDate)->toDateString() : null;

        // For vendors, we'd track PO/payables (not implemented yet)
        // Placeholder structure for consistency

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
            'payables' => [],
            'summary' => [
                'total_payables' => 0,
                'total_paid' => 0,
            ],
        ];
    }
}
