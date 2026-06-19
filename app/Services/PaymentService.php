<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceSettlement;
use App\Models\Receipt;
use App\Models\Payment;
use App\Models\Vendor;
use App\Models\Order;
use App\Models\VendorPaymentAllocation;
use Illuminate\Database\Eloquent\Collection;
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
        ?int $createdByUserId = null,
        ?string $paymentDate = null,
        ?string $narration = null
    ): InvoiceSettlement {
        return DB::transaction(function () use ($invoice, $amount, $paymentMethod, $accountId, $referenceNumber, $createdByUserId, $paymentDate, $narration) {
            $recordedDate = $paymentDate ?: now()->toDateString();

            // Create receipt
            $receipt = Receipt::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $invoice->tenant_id,
                'company_id' => $invoice->company_id,
                'customer_id' => $invoice->customer_id,
                'receipt_number' => $this->generateReceiptNumber($invoice->company_id),
                'receipt_date' => $recordedDate,
                'amount' => $amount,
                'currency_code' => $invoice->currency_code,
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber,
                'description' => $narration,
                'created_by_user_id' => $createdByUserId ?? Auth::id() ?? 1,
            ]);

            // Create settlement record
            $settlement = InvoiceSettlement::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'amount_received' => $amount,
                'settlement_date' => $recordedDate,
                'settlement_type' => 'payment',
                'reference_document_id' => $receipt->id,
                'reference_document_type' => Receipt::class,
                'notes' => $narration,
            ]);

            // Update invoice status and outstanding amount
            $outstandingAfter = max(0, (float) $invoice->outstanding_amount - $amount);
            $newStatus = $outstandingAfter == 0 ? 'paid' : 'partial_paid';

            $invoice->update([
                'outstanding_amount' => $outstandingAfter,
                'status' => $newStatus,
            ]);

            $invoice->order()->update(['status' => $newStatus]);

            // Record ledger entry if account provided
            if ($accountId) {
                $ledger = app(LedgerService::class);
                if ($paymentMethod === 'cash') {
                    $ledger->recordCashDeposit($invoice->tenant_id, $accountId, $amount, 'customer_receipt', $receipt->id);
                } elseif ($paymentMethod === 'bank_transfer') {
                    $ledger->recordBankDeposit($invoice->tenant_id, $accountId, $amount, 'customer_receipt', $receipt->id);
                }
            }

            return $settlement->fresh();
        });
    }

    /**
     * Record one customer receipt using explicit allocations, or oldest-first for legacy callers.
     */
    public function recordBulkPayment(
        Collection $invoices,
        float $amount,
        string $paymentMethod = 'cash',
        ?int $accountId = null,
        string $referenceNumber = '',
        ?int $createdByUserId = null,
        ?string $paymentDate = null,
        ?string $narration = null,
        array $requestedAllocations = []
    ): array {
        return DB::transaction(function () use ($invoices, $amount, $paymentMethod, $accountId, $referenceNumber, $createdByUserId, $paymentDate, $narration, $requestedAllocations): array {
            $lockedInvoices = Invoice::whereIn('id', $invoices->modelKeys())
                ->orderBy('invoice_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $availableBalance = (float) $lockedInvoices->sum('outstanding_amount');

            if ($amount > $availableBalance) {
                throw new \InvalidArgumentException('Payment cannot exceed the selected outstanding balance.');
            }

            if ($requestedAllocations && abs(array_sum($requestedAllocations) - $amount) > 0.0001) {
                throw new \InvalidArgumentException('The receipt amount must equal the allocation total.');
            }

            $firstInvoice = $lockedInvoices->firstOrFail();
            $recordedDate = $paymentDate ?: now()->toDateString();
            $receipt = Receipt::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $firstInvoice->tenant_id,
                'company_id' => $firstInvoice->company_id,
                'customer_id' => $firstInvoice->customer_id,
                'receipt_number' => $this->generateReceiptNumber($firstInvoice->company_id),
                'receipt_date' => $recordedDate,
                'amount' => $amount,
                'currency_code' => $firstInvoice->currency_code,
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber,
                'description' => $narration,
                'created_by_user_id' => $createdByUserId ?? Auth::id() ?? 1,
            ]);

            $remaining = $amount;
            $settlements = collect();
            $allocations = [];

            foreach ($lockedInvoices as $invoice) {
                if ($remaining <= 0) {
                    break;
                }

                $allocation = $requestedAllocations
                    ? (float) ($requestedAllocations[$invoice->uid] ?? 0)
                    : min($remaining, (float) $invoice->outstanding_amount);
                if ($allocation <= 0) {
                    continue;
                }
                if ($allocation > (float) $invoice->outstanding_amount) {
                    throw new \InvalidArgumentException('An allocation exceeds the selected invoice balance.');
                }
                $outstandingAfter = max(0, (float) $invoice->outstanding_amount - $allocation);
                $newStatus = $outstandingAfter == 0 ? 'paid' : 'partial_paid';

                $settlement = InvoiceSettlement::create([
                    'uid' => (string) Str::ulid(),
                    'tenant_id' => $invoice->tenant_id,
                    'invoice_id' => $invoice->id,
                    'amount_received' => $allocation,
                    'settlement_date' => $recordedDate,
                    'settlement_type' => 'payment',
                    'reference_document_id' => $receipt->id,
                    'reference_document_type' => Receipt::class,
                    'notes' => $narration,
                ]);

                $invoice->update([
                    'outstanding_amount' => $outstandingAfter,
                    'status' => $newStatus,
                ]);
                $invoice->order()->update(['status' => $newStatus]);

                $settlements->push($settlement);
                $allocations[] = [
                    'invoice_uid' => $invoice->uid,
                    'invoice_number' => $invoice->invoice_number,
                    'amount' => $allocation,
                ];
                $remaining -= $allocation;
            }

            if ($accountId) {
                $ledger = app(LedgerService::class);
                if ($paymentMethod === 'cash') {
                    $ledger->recordCashDeposit($firstInvoice->tenant_id, $accountId, $amount, 'customer_receipt', $receipt->id);
                } elseif ($paymentMethod === 'bank_transfer') {
                    $ledger->recordBankDeposit($firstInvoice->tenant_id, $accountId, $amount, 'customer_receipt', $receipt->id);
                }
            }

            return [
                'receipt' => $receipt->fresh(),
                'settlements' => $settlements,
                'allocations' => $allocations,
            ];
        });
    }

    /**
     * Record a payment made to a vendor, independently of customer receipts.
     */
    public function recordVendorPayment(
        Vendor $vendor,
        float $amount,
        string $paymentMethod = 'cash',
        ?int $accountId = null,
        ?string $accountType = null,
        string $referenceNumber = '',
        ?int $createdByUserId = null,
        ?string $paymentDate = null,
        ?string $narration = null,
        array $allocations = []
    ): Payment {
        return DB::transaction(function () use ($vendor, $amount, $paymentMethod, $accountId, $accountType, $referenceNumber, $createdByUserId, $paymentDate, $narration, $allocations): Payment {
            $payment = Payment::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $vendor->tenant_id,
                'company_id' => $vendor->company_id,
                'vendor_id' => $vendor->id,
                'payment_number' => $this->generateVendorPaymentNumber($vendor->company_id),
                'payment_date' => $paymentDate ?: now()->toDateString(),
                'amount' => $amount,
                'currency_code' => $vendor->currency_code ?: $vendor->company->base_currency_code,
                'payment_method' => $paymentMethod,
                'account_id' => $accountId,
                'account_type' => $accountType,
                'reference_number' => $referenceNumber,
                'description' => $narration,
                'created_by_user_id' => $createdByUserId ?? Auth::id() ?? 1,
            ]);

            foreach ($allocations as $allocation) {
                VendorPaymentAllocation::create([
                    'uid' => (string) Str::ulid(),
                    'tenant_id' => $vendor->tenant_id,
                    'payment_id' => $payment->id,
                    'order_id' => $allocation['order_id'],
                    'amount' => $allocation['amount'],
                ]);
            }

            if ($accountId && $accountType === 'bank') {
                app(LedgerService::class)->recordBankWithdrawal(
                    $vendor->tenant_id,
                    $accountId,
                    $amount,
                    'vendor_payment',
                    $payment->id
                );
            } elseif ($accountId && $accountType === 'cash') {
                app(LedgerService::class)->recordCashWithdrawal(
                    $vendor->tenant_id,
                    $accountId,
                    $amount,
                    'vendor_payment',
                    $payment->id
                );
            }

            return $payment->fresh(['vendor', 'allocations.order:id,order_number']);
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

    public function generateVendorPaymentNumber(int $companyId): string
    {
        $prefix = 'VP-' . now()->format('Ymd');
        $lastPayment = Payment::where('company_id', $companyId)
            ->where('payment_number', 'LIKE', $prefix . '%')
            ->orderByDesc('payment_number')
            ->first();
        $sequence = $lastPayment ? ((int) substr($lastPayment->payment_number, -5)) + 1 : 1;

        return $prefix . '-' . str_pad($sequence, 5, '0', STR_PAD_LEFT);
    }
}
