<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceSettlement;
use App\Models\Receipt;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    /**
     * Record customer payment and apply to invoice
     */
    public function recordPayment(
        Invoice $invoice,
        float $amount,
        string $paymentMethod = 'cash',
        ?int $accountId = null,
        string $referenceNumber = '',
        ?int $createdByUserId = null
    ): InvoiceSettlement {
        return DB::transaction(function () use ($invoice, $amount, $paymentMethod, $accountId, $referenceNumber, $createdByUserId) {
            // Create receipt
            $receipt = Receipt::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $invoice->tenant_id,
                'company_id' => $invoice->company_id,
                'customer_id' => $invoice->customer_id,
                'receipt_number' => $this->generateReceiptNumber($invoice->company_id),
                'receipt_date' => now()->toDateString(),
                'amount' => $amount,
                'currency_code' => $invoice->currency_code,
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber,
                'created_by_user_id' => $createdByUserId ?? Auth::id() ?? 1,
            ]);

            // Create settlement record
            $settlement = InvoiceSettlement::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'amount_received' => $amount,
                'settlement_date' => now()->toDateString(),
                'settlement_type' => 'payment',
                'reference_document_id' => $receipt->id,
                'reference_document_type' => Receipt::class,
            ]);

            // Update invoice status and outstanding amount
            $outstandingAfter = max(0, (float)$invoice->total_amount - $amount);
            $newStatus = $outstandingAfter == 0 ? 'paid' : 'partial_paid';

            $invoice->update([
                'outstanding_amount' => $outstandingAfter,
                'status' => $newStatus,
            ]);

            // Record ledger entry if account provided
            if ($accountId && $paymentMethod === 'bank_transfer') {
                app(LedgerService::class)->recordBankDeposit(
                    $invoice->tenant_id,
                    $accountId,
                    $amount,
                    'payment',
                    $receipt->id
                );
            }

            return $settlement->fresh();
        });
    }

    /**
     * Record refund against payment
     */
    public function recordRefund(
        Invoice $invoice,
        float $amount,
        string $reason = '',
        ?int $accountId = null,
        ?int $createdByUserId = null
    ): InvoiceSettlement {
        return DB::transaction(function () use ($invoice, $amount, $reason, $accountId, $createdByUserId) {
            $settlement = InvoiceSettlement::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'amount_refunded' => $amount,
                'settlement_date' => now()->toDateString(),
                'settlement_type' => 'refund',
                'notes' => $reason,
            ]);

            // Revert invoice outstanding amount
            $outstandingAfter = (float)$invoice->outstanding_amount + $amount;
            $newStatus = $outstandingAfter == 0 ? 'paid' : ($outstandingAfter > 0 ? 'partial_paid' : 'paid');

            $invoice->update([
                'outstanding_amount' => $outstandingAfter,
                'status' => $newStatus,
            ]);

            return $settlement->fresh();
        });
    }

    /**
     * Record advance payment / overpayment
     */
    public function recordAdvance(
        Invoice $invoice,
        float $amount,
        string $paymentMethod = 'cash',
        ?int $accountId = null,
        ?int $createdByUserId = null
    ): InvoiceSettlement {
        return DB::transaction(function () use ($invoice, $amount, $paymentMethod, $accountId, $createdByUserId) {
            $settlement = InvoiceSettlement::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'amount_to_advance' => $amount,
                'settlement_date' => now()->toDateString(),
                'settlement_type' => 'advance',
            ]);

            // Update advance balance
            $newAdvance = (float)$invoice->advance_balance + $amount;
            $invoice->update([
                'advance_balance' => $newAdvance,
            ]);

            return $settlement->fresh();
        });
    }

    /**
     * Apply advance balance to outstanding amount
     */
    public function applyAdvance(Invoice $invoice, float $advanceAmount): InvoiceSettlement
    {
        return DB::transaction(function () use ($invoice, $advanceAmount) {
            $applied = min($advanceAmount, (float)$invoice->outstanding_amount);

            $settlement = InvoiceSettlement::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'amount_received' => $applied,
                'settlement_date' => now()->toDateString(),
                'settlement_type' => 'payment',
                'notes' => 'Applied from advance balance',
            ]);

            $outstandingAfter = max(0, (float)$invoice->outstanding_amount - $applied);
            $advanceAfter = (float)$invoice->advance_balance - $applied;

            $newStatus = $outstandingAfter == 0 ? 'paid' : 'partial_paid';

            $invoice->update([
                'outstanding_amount' => $outstandingAfter,
                'advance_balance' => $advanceAfter,
                'status' => $newStatus,
            ]);

            return $settlement->fresh();
        });
    }

    /**
     * Generate receipt number
     */
    public function generateReceiptNumber(int $companyId): string
    {
        $today = now()->format('Ymd');
        $prefix = 'RCP-' . $today;

        $lastReceipt = Receipt::where('company_id', $companyId)
            ->where('receipt_number', 'LIKE', $prefix . '%')
            ->orderBy('receipt_number', 'desc')
            ->first();

        $sequence = 1;
        if ($lastReceipt) {
            $lastSeq = (int) substr($lastReceipt->receipt_number, -5);
            $sequence = $lastSeq + 1;
        }

        return $prefix . '-' . str_pad($sequence, 5, '0', STR_PAD_LEFT);
    }
}
