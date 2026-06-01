<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class InvoiceService
{
    /**
     * Create invoice from order
     */
    public function createFromOrder(Order $order): Invoice
    {
        $invoice = new Invoice();
        $invoice->uid = (string) Str::ulid();
        $invoice->tenant_id = $order->tenant_id;
        $invoice->company_id = $order->company_id;
        $invoice->order_id = $order->id;
        $invoice->customer_id = $order->customer_id;
        $invoice->invoice_date = now()->toDateString();
        $invoice->due_date = now()->addDays(30)->toDateString();
        $invoice->currency_code = $order->company->base_currency_code;
        $invoice->invoice_number = $this->generateInvoiceNumber($order->company_id);
        $invoice->subtotal = $order->total_amount;
        $invoice->tax_amount = 0;
        $invoice->total_amount = $order->total_amount;
        $invoice->outstanding_amount = $order->total_amount;
        $invoice->fx_rate_to_base = 1;
        $invoice->save();

        // Create invoice lines from order items
        foreach ($order->items as $item) {
            InvoiceLine::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $order->tenant_id,
                'invoice_id' => $invoice->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'total_price' => $item->total_price,
            ]);
        }

        return $invoice->fresh(['lines']);
    }

    /**
     * Generate sequential invoice number per company
     */
    public function generateInvoiceNumber(int $companyId): string
    {
        $today = now()->format('Ymd');
        $prefix = 'INV-' . $today;

        $lastInvoice = Invoice::where('company_id', $companyId)
            ->where('invoice_number', 'LIKE', $prefix . '%')
            ->orderBy('invoice_number', 'desc')
            ->first();

        $sequence = 1;
        if ($lastInvoice) {
            $lastSeq = (int) substr($lastInvoice->invoice_number, -5);
            $sequence = $lastSeq + 1;
        }

        return $prefix . '-' . str_pad($sequence, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Calculate outstanding balance
     */
    public function calculateOutstanding(Invoice $invoice): float
    {
        $totalSettled = $invoice->settlements()
            ->where('settlement_type', 'payment')
            ->sum('amount_received');

        return max(0, (float)$invoice->total_amount - $totalSettled);
    }

    /**
     * Calculate advance balance
     */
    public function calculateAdvance(Invoice $invoice): float
    {
        return (float) $invoice->settlements()
            ->where('settlement_type', 'advance')
            ->sum('amount_to_advance');
    }

    /**
     * Mark invoice as sent
     */
    public function markAsSent(Invoice $invoice): Invoice
    {
        $invoice->update(['status' => 'sent']);
        return $invoice->fresh();
    }

    /**
     * Check if invoice can be recorded as paid
     */
    public function isPaid(Invoice $invoice): bool
    {
        return $this->calculateOutstanding($invoice) <= 0;
    }

    /**
     * Check if invoice is overdue
     */
    public function isOverdue(Invoice $invoice): bool
    {
        return $invoice->due_date && $invoice->due_date < now()->toDateString()
            && in_array($invoice->status, ['issued', 'sent', 'partial_paid']);
    }
}
