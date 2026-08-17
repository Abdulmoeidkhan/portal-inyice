<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDiscount;
use App\Models\InvoiceLine;
use App\Models\Order;
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
                ->whereNotIn('status', ['void', 'cancel'])
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

            if (in_array($previousInvoice->status, ['void', 'cancel'], true)) {
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
                'status' => 'cancel',
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
        $subtotal = (float) $order->items->sum(fn ($item) => max(0, (float) $item->total_price));
        $total = max(0, (float) $order->items->sum('total_price'));
        $invoice->subtotal = $subtotal;
        $invoice->tax_amount = 0;
        $invoice->total_amount = $total;
        $invoice->outstanding_amount = $total;
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

    public function applyDiscount(Invoice $invoice, float $amount, ?string $reason = null, ?int $userId = null): Invoice
    {
        $this->createDiscount($invoice, 'amount', $amount, $reason, $userId);

        return $invoice->fresh(['lines', 'discounts.createdBy:id,name', 'discounts.updatedBy:id,name', 'settlements.referenceDocument']);
    }

    public function createDiscount(Invoice $invoice, string $discountType, float $value, ?string $reason = null, ?int $userId = null): InvoiceDiscount
    {
        return DB::transaction(function () use ($invoice, $discountType, $value, $reason, $userId): InvoiceDiscount {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->ensureDiscountable($invoice);
            [$amount, $percentage] = $this->discountAmountFromInput($invoice, $discountType, $value);

            if ($amount > $this->maximumNewDiscountAmount($invoice)) {
                throw ValidationException::withMessages(['amount' => 'Discount cannot exceed the current outstanding balance.']);
            }

            $line = InvoiceLine::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'description' => $this->discountDescription($reason),
                'quantity' => 1,
                'unit_price' => -$amount,
                'total_price' => -$amount,
            ]);

            $discount = InvoiceDiscount::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $invoice->tenant_id,
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'invoice_line_id' => $line->id,
                'discount_type' => $discountType,
                'percentage' => $percentage,
                'amount' => $amount,
                'reason' => $reason,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]);

            $this->recalculateInvoiceFinancials($invoice);

            return $discount->fresh(['invoiceLine', 'createdBy:id,name', 'updatedBy:id,name']);
        });
    }

    public function updateDiscount(InvoiceDiscount $discount, string $discountType, float $value, ?string $reason = null, ?int $userId = null): InvoiceDiscount
    {
        return DB::transaction(function () use ($discount, $discountType, $value, $reason, $userId): InvoiceDiscount {
            $discount = InvoiceDiscount::whereKey($discount->id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::whereKey($discount->invoice_id)->lockForUpdate()->firstOrFail();
            $this->ensureDiscountable($invoice);
            [$amount, $percentage] = $this->discountAmountFromInput($invoice, $discountType, $value, $discount);

            if ($amount > $this->maximumNewDiscountAmount($invoice, $discount)) {
                throw ValidationException::withMessages(['amount' => 'Discount cannot exceed the current outstanding balance.']);
            }

            $discount->invoiceLine?->update([
                'description' => $this->discountDescription($reason),
                'unit_price' => -$amount,
                'total_price' => -$amount,
            ]);

            $discount->update([
                'discount_type' => $discountType,
                'percentage' => $percentage,
                'amount' => $amount,
                'reason' => $reason,
                'updated_by_user_id' => $userId,
            ]);

            $this->recalculateInvoiceFinancials($invoice);

            return $discount->fresh(['invoiceLine', 'createdBy:id,name', 'updatedBy:id,name']);
        });
    }

    public function deleteDiscount(InvoiceDiscount $discount): void
    {
        DB::transaction(function () use ($discount): void {
            $discount = InvoiceDiscount::whereKey($discount->id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::whereKey($discount->invoice_id)->lockForUpdate()->firstOrFail();
            $this->ensureDiscountable($invoice);
            $line = $discount->invoiceLine;

            $discount->delete();
            $line?->delete();

            $this->recalculateInvoiceFinancials($invoice);
        });
    }

    public function recalculateInvoiceFinancials(Invoice $invoice): Invoice
    {
        $invoice->load('lines');
        $subtotal = $invoice->lines
            ->filter(fn (InvoiceLine $line) => (float) $line->total_price > 0)
            ->sum(fn (InvoiceLine $line) => (float) $line->total_price);
        $discount = abs($invoice->lines
            ->filter(fn (InvoiceLine $line) => (float) $line->total_price < 0)
            ->sum(fn (InvoiceLine $line) => (float) $line->total_price));
        $total = max(0, $subtotal + (float) $invoice->tax_amount - $discount);
        $received = (float) $invoice->settlements()->where('status', 'confirmed')->sum('amount_received');
        $refunded = (float) $invoice->settlements()->where('status', 'confirmed')->sum('amount_refunded');
        $outstanding = min($total, max(0, $total - $received + $refunded));
        $status = $outstanding <= 0 ? 'paid' : (($received - $refunded) > 0 ? 'partial_paid' : 'issued');

        $invoice->update([
            'subtotal' => $subtotal,
            'total_amount' => $total,
            'outstanding_amount' => $outstanding,
            'status' => $status,
        ]);

        $orderStatus = $refunded > 0
            ? ($refunded >= $received ? 'refund' : 'partial_refund')
            : ($status === 'issued' ? 'invoice' : $status);
        $invoice->order()->update(['status' => $orderStatus]);

        return $invoice->fresh(['lines', 'discounts.createdBy:id,name', 'discounts.updatedBy:id,name', 'settlements.referenceDocument']);
    }

    private function ensureDiscountable(Invoice $invoice): void
    {
        if (in_array($invoice->status, ['void', 'cancel'], true)) {
            throw ValidationException::withMessages(['invoice' => 'Discounts can only be changed on active invoices.']);
        }
    }

    private function maximumNewDiscountAmount(Invoice $invoice, ?InvoiceDiscount $replacing = null): float
    {
        $received = (float) $invoice->settlements()->where('status', 'confirmed')->sum('amount_received');
        $refunded = (float) $invoice->settlements()->where('status', 'confirmed')->sum('amount_refunded');
        $paidNet = max(0, $received - $refunded);
        $positiveLines = (float) $invoice->lines()->where('total_price', '>', 0)->sum('total_price');
        $otherDiscounts = (float) $invoice->discounts()
            ->when($replacing, fn ($query) => $query->whereKeyNot($replacing->id))
            ->sum('amount');

        return max(0, $positiveLines + (float) $invoice->tax_amount - $paidNet - $otherDiscounts);
    }

    private function percentageDiscountBase(Invoice $invoice, ?InvoiceDiscount $replacing = null): float
    {
        $positiveLines = (float) $invoice->lines()->where('total_price', '>', 0)->sum('total_price');
        $otherDiscounts = (float) $invoice->discounts()
            ->when($replacing, fn ($query) => $query->whereKeyNot($replacing->id))
            ->sum('amount');

        return max(0, $positiveLines + (float) $invoice->tax_amount - $otherDiscounts);
    }

    private function discountAmountFromInput(Invoice $invoice, string $discountType, float $value, ?InvoiceDiscount $replacing = null): array
    {
        if (!in_array($discountType, ['amount', 'percentage'], true)) {
            throw ValidationException::withMessages(['discount_type' => 'Discount type must be amount or percentage.']);
        }

        if ($value <= 0) {
            throw ValidationException::withMessages([$discountType === 'percentage' ? 'percentage' : 'amount' => 'Discount value must be greater than zero.']);
        }

        if ($discountType === 'percentage') {
            if ($value > 100) {
                throw ValidationException::withMessages(['percentage' => 'Percentage discount cannot exceed 100%.']);
            }

            $amount = round($this->percentageDiscountBase($invoice, $replacing) * ($value / 100), 4);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['percentage' => 'Percentage discount does not produce a billable discount amount.']);
            }

            return [$amount, $value];
        }

        return [$value, null];
    }

    private function discountDescription(?string $reason): string
    {
        return trim('Discount' . ($reason ? ': ' . $reason : ''));
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
