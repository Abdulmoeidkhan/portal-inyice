<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    /**
     * Create invoice from order
     */
    public function createFromOrder(Order $order): Invoice
    {
        return DB::transaction(function () use ($order): Invoice {
            $existingInvoice = Invoice::where('tenant_id', $order->tenant_id)
                ->where('company_id', $order->company_id)
                ->where('order_id', $order->id)
                ->where('status', '!=', 'void')
                ->latest('id')
                ->first();

            if ($existingInvoice) {
                return $existingInvoice->load('lines');
            }

            $order->loadMissing(['company', 'items']);

            $invoice = $this->createFreshInvoice($order);

            $order->update(['status' => 'invoice']);

            return $invoice->fresh(['lines']);
        });
    }

    public function reviseFromOrder(Order $order, Invoice $previousInvoice): Invoice
    {
        return DB::transaction(function () use ($order, $previousInvoice): Invoice {
            $order->loadMissing(['company', 'items']);
            $previousInvoice = Invoice::whereKey($previousInvoice->id)->lockForUpdate()->firstOrFail();

            if ($previousInvoice->status === 'void') {
                return $this->createFreshInvoice($order)->fresh(['lines']);
            }

            $replacement = $this->createFreshInvoice($order);
            $timestamp = now()->format('Y-m-d H:i:s');
            $revisionNote = "Canceled on {$timestamp} because order {$order->order_number} was revised. Replacement invoice: {$replacement->invoice_number}.";
            $existingNotes = trim((string) $previousInvoice->notes);

            $previousInvoice->lines()->update([
                'unit_price' => 0,
                'total_price' => 0,
            ]);

            $previousInvoice->update([
                'subtotal' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'outstanding_amount' => 0,
                'advance_balance' => 0,
                'status' => 'void',
                'notes' => $existingNotes !== '' ? $existingNotes . "\n" . $revisionNote : $revisionNote,
            ]);

            $order->update(['status' => 'invoice']);

            return $replacement->fresh(['lines']);
        });
    }

    private function createFreshInvoice(Order $order): Invoice
    {
        $order->loadMissing(['company', 'items']);

        $invoiceDate = now();
        $this->ensureMonthlyInvoiceLimitIsAvailable(
            (int) $order->tenant_id,
            (int) $order->company_id,
            $invoiceDate,
            (int) ($order->company?->monthly_invoice_limit ?: 50),
        );

        $invoice = new Invoice();
        $invoice->uid = (string) Str::ulid();
        $invoice->tenant_id = $order->tenant_id;
        $invoice->company_id = $order->company_id;
        $invoice->order_id = $order->id;
        $invoice->customer_id = $order->customer_id;
        $invoice->invoice_date = $invoiceDate->toDateString();
        $invoice->due_date = $invoiceDate->copy()->addDays(30)->toDateString();
        $invoice->currency_code = $order->currency_code ?: $order->company->base_currency_code;
        $invoice->invoice_number = $this->generateInvoiceNumber($order->company_id);
        $invoice->subtotal = $order->total_amount;
        $invoice->tax_amount = 0;
        $invoice->total_amount = $order->total_amount;
        $invoice->outstanding_amount = $order->total_amount;
        $invoice->status = 'issued';
        $invoice->fx_rate_to_base = 1;
        $invoice->save();

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

        return $invoice;
    }

    private function ensureMonthlyInvoiceLimitIsAvailable(int $tenantId, int $companyId, \DateTimeInterface $invoiceDate, int $limit): void
    {
        $invoiceMonth = CarbonImmutable::instance($invoiceDate);
        $monthStart = $invoiceMonth->startOfMonth()->toDateString();
        $monthEnd = $invoiceMonth->endOfMonth()->toDateString();

        $monthlyCount = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereBetween('invoice_date', [$monthStart, $monthEnd])
            ->count();

        if ($monthlyCount >= $limit) {
            throw ValidationException::withMessages([
                'invoice_limit' => "This company has reached the monthly limit of {$limit} invoices.",
            ]);
        }
    }

    /**
     * Generate sequential invoice number per company
     */
    public function generateInvoiceNumber(int $companyId): string
    {
        $prefix = 'INV-' . now()->format('Ymd-His');

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
