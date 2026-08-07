<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceSettlement;
use App\Models\Receipt;
use App\Models\Payment;
use App\Models\Vendor;
use App\Models\Order;
use App\Models\VendorPaymentAllocation;
use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    /**
     * Record customer payment and apply to invoice
     */
    public function recordCustomerReceipt(
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
                'account_id' => $accountId,
                'account_type' => $accountId ? ($paymentMethod === 'cash' ? 'cash' : 'bank') : null,
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
    public function recordBulkCustomerReceipt(
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
                'account_id' => $accountId,
                'account_type' => $accountId ? ($paymentMethod === 'cash' ? 'cash' : 'bank') : null,
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

    public function recordCustomerPayment(
        \App\Models\Customer $customer,
        float $amount,
        string $paymentMethod,
        ?int $accountId,
        ?string $referenceNumber,
        ?string $description,
        ?string $paymentDate,
        ?int $createdByUserId = null
    ): Payment {
        return DB::transaction(function () use ($customer, $amount, $paymentMethod, $accountId, $referenceNumber, $description, $paymentDate, $createdByUserId): Payment {
            $payment = Payment::create([
                'uid' => (string) Str::ulid(), 'tenant_id' => $customer->tenant_id,
                'company_id' => $customer->company_id, 'customer_id' => $customer->id,
                'payment_number' => $this->generatePaymentNumber($customer->company_id),
                'payment_date' => $paymentDate ?: now()->toDateString(), 'amount' => $amount,
                'currency_code' => $customer->currency_code ?: $customer->company->base_currency_code,
                'payment_method' => $paymentMethod, 'account_id' => $accountId,
                'account_type' => $accountId ? ($paymentMethod === 'cash' ? 'cash' : 'bank') : null,
                'reference_number' => $referenceNumber, 'description' => $description,
                'created_by_user_id' => $createdByUserId ?? Auth::id() ?? 1,
            ]);
            $this->recordVendorLedger($payment);
            return $payment->fresh('customer:id,name');
        });
    }

    public function recordVendorReceipt(
        Vendor $vendor,
        float $amount,
        string $paymentMethod,
        ?int $accountId,
        ?string $referenceNumber,
        ?string $description,
        ?string $receiptDate,
        ?int $createdByUserId = null
    ): Receipt {
        return DB::transaction(function () use ($vendor, $amount, $paymentMethod, $accountId, $referenceNumber, $description, $receiptDate, $createdByUserId): Receipt {
            $receipt = Receipt::create([
                'uid' => (string) Str::ulid(), 'tenant_id' => $vendor->tenant_id,
                'company_id' => $vendor->company_id, 'vendor_id' => $vendor->id,
                'receipt_number' => $this->generateReceiptNumber($vendor->company_id),
                'receipt_date' => $receiptDate ?: now()->toDateString(), 'amount' => $amount,
                'currency_code' => $vendor->currency_code ?: $vendor->company->base_currency_code,
                'payment_method' => $paymentMethod, 'account_id' => $accountId,
                'account_type' => $accountId ? ($paymentMethod === 'cash' ? 'cash' : 'bank') : null,
                'reference_number' => $referenceNumber, 'description' => $description,
                'created_by_user_id' => $createdByUserId ?? Auth::id() ?? 1,
            ]);
            $this->recordReceiptLedger($receipt);
            return $receipt->fresh('vendor:id,name');
        });
    }

    public function recordCustomerAdvanceReceipt(
        \App\Models\Customer $customer,
        float $amount,
        string $paymentMethod,
        ?int $accountId,
        ?string $referenceNumber,
        ?string $description,
        ?string $receiptDate,
        ?int $createdByUserId = null
    ): Receipt {
        return DB::transaction(function () use ($customer, $amount, $paymentMethod, $accountId, $referenceNumber, $description, $receiptDate, $createdByUserId): Receipt {
            $receipt = Receipt::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $customer->tenant_id,
                'company_id' => $customer->company_id,
                'customer_id' => $customer->id,
                'receipt_number' => $this->generateReceiptNumber($customer->company_id),
                'receipt_date' => $receiptDate ?: now()->toDateString(),
                'amount' => $amount,
                'currency_code' => $customer->currency_code ?: $customer->company->base_currency_code,
                'payment_method' => $paymentMethod,
                'account_id' => $accountId,
                'account_type' => $accountId ? ($paymentMethod === 'cash' ? 'cash' : 'bank') : null,
                'reference_number' => $referenceNumber,
                'description' => $description ?: 'Customer advance receipt',
                'created_by_user_id' => $createdByUserId ?? Auth::id() ?? 1,
            ]);

            $this->recordReceiptLedger($receipt);

            return $receipt->fresh('customer:id,name');
        });
    }

    public function updateReceipt(Receipt $receipt, Collection $invoices, array $data): Receipt
    {
        return DB::transaction(function () use ($receipt, $invoices, $data): Receipt {
            $receipt = Receipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $oldInvoiceIds = $receipt->settlements()->pluck('invoice_id');
            $lockedInvoices = Invoice::whereIn('id', $oldInvoiceIds->merge($invoices->modelKeys())->unique())
                ->lockForUpdate()->get()->keyBy('id');

            $receipt->settlements()->delete();
            LedgerEntry::where('tenant_id', $receipt->tenant_id)
                ->where('reference_type', 'receipt')->where('reference_id', $receipt->id)->delete();
            foreach ($oldInvoiceIds as $invoiceId) {
                if ($lockedInvoices->has($invoiceId)) $this->recalculateInvoice($lockedInvoices[$invoiceId]);
            }

            $allocations = collect($data['allocations']);
            $total = (float) $allocations->sum('amount');
            foreach ($allocations as $allocation) {
                $invoice = $lockedInvoices[(int) $allocation['invoice_id']] ?? null;
                if (!$invoice || $invoice->customer_id !== $receipt->customer_id || $invoice->company_id !== $receipt->company_id) {
                    throw new \InvalidArgumentException('A selected invoice is not available for this receipt.');
                }
                if ((float) $allocation['amount'] > (float) $invoice->outstanding_amount) {
                    throw new \InvalidArgumentException('An allocation exceeds the selected invoice balance.');
                }
                InvoiceSettlement::create([
                    'uid' => (string) Str::ulid(), 'tenant_id' => $receipt->tenant_id,
                    'invoice_id' => $invoice->id, 'amount_received' => $allocation['amount'],
                    'settlement_date' => $data['date'], 'settlement_type' => 'payment',
                    'reference_document_id' => $receipt->id, 'reference_document_type' => Receipt::class,
                    'notes' => $data['description'] ?? null,
                ]);
                $this->recalculateInvoice($invoice);
            }
            foreach ($lockedInvoices as $invoice) {
                $received = (float) $invoice->settlements()->where('status', 'confirmed')->sum('amount_received');
                $refunded = (float) $invoice->settlements()->where('status', 'confirmed')->sum('amount_refunded');
                if ($refunded > $received) throw new \InvalidArgumentException('This change would exceed an invoice refundable paid amount.');
            }

            $receipt->update([
                'receipt_date' => $data['date'], 'amount' => $total,
                'payment_method' => $data['payment_method'], 'account_id' => $data['account_id'] ?? null,
                'account_type' => ($data['account_id'] ?? null) ? ($data['payment_method'] === 'cash' ? 'cash' : 'bank') : null,
                'reference_number' => $data['reference_number'] ?? null, 'description' => $data['description'] ?? null,
            ]);
            $this->recordReceiptLedger($receipt);

            return $receipt->fresh(['customer:id,name', 'settlements.invoice:id,invoice_number']);
        });
    }

    public function allocateCustomerAdvanceReceipt(Receipt $receipt, Collection $invoices, array $data): Receipt
    {
        return DB::transaction(function () use ($receipt, $invoices, $data): Receipt {
            $receipt = Receipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $lockedInvoices = Invoice::whereIn('id', $invoices->modelKeys())->lockForUpdate()->get()->keyBy('id');
            $allocations = collect($data['allocations']);
            $total = (float) $allocations->sum('amount');
            $allocated = (float) $receipt->settlements()->where('status', 'confirmed')->sum('amount_received');
            $remaining = max(0, (float) $receipt->amount - $allocated);

            if ($total > $remaining) {
                throw new \InvalidArgumentException('Advance allocation cannot exceed the remaining advance balance.');
            }

            foreach ($allocations as $allocation) {
                $invoice = $lockedInvoices[(int) $allocation['invoice_id']] ?? null;
                if (!$invoice || $invoice->customer_id !== $receipt->customer_id || $invoice->company_id !== $receipt->company_id || $invoice->currency_code !== $receipt->currency_code) {
                    throw new \InvalidArgumentException('A selected invoice is not available for this advance receipt.');
                }
                if ((float) $allocation['amount'] > (float) $invoice->outstanding_amount) {
                    throw new \InvalidArgumentException('An allocation exceeds the selected invoice balance.');
                }

                InvoiceSettlement::create([
                    'uid' => (string) Str::ulid(),
                    'tenant_id' => $receipt->tenant_id,
                    'invoice_id' => $invoice->id,
                    'amount_received' => $allocation['amount'],
                    'settlement_date' => $data['date'] ?? now()->toDateString(),
                    'settlement_type' => 'payment',
                    'reference_document_id' => $receipt->id,
                    'reference_document_type' => Receipt::class,
                    'notes' => $data['notes'] ?? 'Applied from advance receipt ' . $receipt->receipt_number,
                ]);

                $this->recalculateInvoice($invoice);
            }

            return $receipt->fresh(['customer:id,name', 'settlements.invoice:id,invoice_number']);
        });
    }

    public function deleteReceipt(Receipt $receipt): void
    {
        DB::transaction(function () use ($receipt): void {
            $receipt = Receipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $invoiceIds = $receipt->settlements()->pluck('invoice_id');
            foreach (Invoice::whereIn('id', $invoiceIds)->get() as $invoice) {
                $otherReceived = (float) $invoice->settlements()->where('status', 'confirmed')
                    ->where(function ($query) use ($receipt) {
                        $query->where('reference_document_type', '!=', Receipt::class)
                            ->orWhereNull('reference_document_type')->orWhere('reference_document_id', '!=', $receipt->id);
                    })->sum('amount_received');
                $refunded = (float) $invoice->settlements()->where('status', 'confirmed')->sum('amount_refunded');
                if ($refunded > $otherReceived) throw new \InvalidArgumentException('A refunded receipt cannot be deleted.');
            }
            $receipt->settlements()->delete();
            LedgerEntry::where('tenant_id', $receipt->tenant_id)
                ->where('reference_type', 'receipt')->where('reference_id', $receipt->id)->delete();
            $receipt->delete();
            Invoice::whereIn('id', $invoiceIds)->lockForUpdate()->get()->each(fn (Invoice $invoice) => $this->recalculateInvoice($invoice));
        });
    }

    public function updateVendorPayment(Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $data): Payment {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            LedgerEntry::where('tenant_id', $payment->tenant_id)
                ->where('reference_type', 'payment')->where('reference_id', $payment->id)->delete();
            $payment->allocations()->delete();
            foreach ($data['allocations'] as $allocation) {
                VendorPaymentAllocation::create([
                    'uid' => (string) Str::ulid(), 'tenant_id' => $payment->tenant_id,
                    'payment_id' => $payment->id, 'order_id' => $allocation['order_id'], 'amount' => $allocation['amount'],
                ]);
            }
            $payment->update([
                'payment_date' => $data['date'], 'amount' => collect($data['allocations'])->sum('amount'),
                'payment_method' => $data['payment_method'], 'account_id' => $data['account_id'] ?? null,
                'account_type' => ($data['account_id'] ?? null) ? ($data['payment_method'] === 'cash' ? 'cash' : 'bank') : null,
                'reference_number' => $data['reference_number'] ?? null, 'description' => $data['description'] ?? null,
            ]);
            $this->recordVendorLedger($payment);
            return $payment->fresh(['vendor:id,name', 'allocations.order:id,order_number']);
        });
    }

    public function deleteVendorPayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            LedgerEntry::where('tenant_id', $payment->tenant_id)
                ->where('reference_type', 'payment')->where('reference_id', $payment->id)->delete();
            $payment->allocations()->delete();
            $payment->delete();
        });
    }

    public function deleteCustomerPayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $settlements = InvoiceSettlement::where('reference_document_type', Payment::class)
                ->where('reference_document_id', $payment->id)->get();
            $invoiceIds = $settlements->pluck('invoice_id');
            InvoiceSettlement::whereIn('id', $settlements->modelKeys())->delete();
            LedgerEntry::where('tenant_id', $payment->tenant_id)->where('reference_type', 'payment')->where('reference_id', $payment->id)->delete();
            $payment->delete();
            Invoice::whereIn('id', $invoiceIds)->get()->each(fn (Invoice $invoice) => $this->recalculateInvoice($invoice));
        });
    }

    public function updateCustomerPayment(Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $data): Payment {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $refunds = InvoiceSettlement::where('reference_document_type', Payment::class)->where('reference_document_id', $payment->id)->get();
            foreach ($refunds as $refund) {
                $invoice = $refund->invoice()->lockForUpdate()->firstOrFail();
                $received = (float) $invoice->settlements()->where('status', 'confirmed')->sum('amount_received');
                $otherRefunded = (float) $invoice->settlements()->where('status', 'confirmed')->where('id', '!=', $refund->id)->sum('amount_refunded');
                if ((float) $data['amount'] > max(0, $received - $otherRefunded)) throw new \InvalidArgumentException('Refund cannot exceed the refundable paid amount.');
                $refund->update(['amount_refunded' => $data['amount'], 'settlement_date' => $data['date'], 'notes' => $data['description'] ?? null]);
                $this->recalculateInvoice($invoice);
            }
            LedgerEntry::where('tenant_id', $payment->tenant_id)->where('reference_type', 'payment')->where('reference_id', $payment->id)->delete();
            $payment->update([
                'payment_date' => $data['date'], 'amount' => $data['amount'], 'payment_method' => $data['payment_method'],
                'account_id' => $data['account_id'] ?? null, 'account_type' => ($data['account_id'] ?? null) ? ($data['payment_method'] === 'cash' ? 'cash' : 'bank') : null,
                'reference_number' => $data['reference_number'] ?? null, 'description' => $data['description'] ?? null,
            ]);
            $this->recordVendorLedger($payment);
            return $payment->fresh('customer:id,name');
        });
    }

    public function deleteVendorReceipt(Receipt $receipt): void
    {
        DB::transaction(function () use ($receipt): void {
            LedgerEntry::where('tenant_id', $receipt->tenant_id)->where('reference_type', 'receipt')->where('reference_id', $receipt->id)->delete();
            $receipt->delete();
        });
    }

    public function updateVendorReceipt(Receipt $receipt, array $data): Receipt
    {
        return DB::transaction(function () use ($receipt, $data): Receipt {
            $receipt = Receipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            LedgerEntry::where('tenant_id', $receipt->tenant_id)->where('reference_type', 'receipt')->where('reference_id', $receipt->id)->delete();
            $receipt->update([
                'receipt_date' => $data['date'], 'amount' => $data['amount'], 'payment_method' => $data['payment_method'],
                'account_id' => $data['account_id'] ?? null, 'account_type' => ($data['account_id'] ?? null) ? ($data['payment_method'] === 'cash' ? 'cash' : 'bank') : null,
                'reference_number' => $data['reference_number'] ?? null, 'description' => $data['description'] ?? null,
            ]);
            $this->recordReceiptLedger($receipt);
            return $receipt->fresh('vendor:id,name');
        });
    }

    private function recalculateInvoice(Invoice $invoice): void
    {
        $received = (float) $invoice->settlements()->where('status', 'confirmed')->sum('amount_received');
        $refunded = (float) $invoice->settlements()->where('status', 'confirmed')->sum('amount_refunded');
        $outstanding = min((float) $invoice->total_amount, max(0, (float) $invoice->total_amount - $received + $refunded));
        $status = $outstanding <= 0 ? 'paid' : (($received - $refunded) > 0 ? 'partial_paid' : 'issued');
        $invoice->update(['outstanding_amount' => $outstanding, 'status' => $status]);
        $orderStatus = $refunded > 0
            ? ($refunded >= $received ? 'refund' : 'partial_refund')
            : ($status === 'issued' ? 'invoice' : $status);
        $invoice->order()->update(['status' => $orderStatus]);
    }

    private function recordReceiptLedger(Receipt $receipt): void
    {
        if (!$receipt->account_id) return;
        $ledger = app(LedgerService::class);
        $receipt->account_type === 'cash'
            ? $ledger->recordCashDeposit($receipt->tenant_id, $receipt->account_id, (float) $receipt->amount, 'customer_receipt', $receipt->id)
            : $ledger->recordBankDeposit($receipt->tenant_id, $receipt->account_id, (float) $receipt->amount, 'customer_receipt', $receipt->id);
    }

    private function recordVendorLedger(Payment $payment): void
    {
        if (!$payment->account_id) return;
        $ledger = app(LedgerService::class);
        $payment->account_type === 'cash'
            ? $ledger->recordCashWithdrawal($payment->tenant_id, $payment->account_id, (float) $payment->amount, 'vendor_payment', $payment->id)
            : $ledger->recordBankWithdrawal($payment->tenant_id, $payment->account_id, (float) $payment->amount, 'vendor_payment', $payment->id);
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
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $received = (float) $invoice->settlements()->where('status', 'confirmed')->sum('amount_received');
            $refunded = (float) $invoice->settlements()->where('status', 'confirmed')->sum('amount_refunded');
            if ($amount > max(0, $received - $refunded)) {
                throw new \InvalidArgumentException('Refund cannot exceed the refundable paid amount.');
            }
            $customerPayment = $this->recordCustomerPayment(
                $invoice->customer()->firstOrFail(), $amount, 'cash', $accountId,
                null, $reason ?: 'Invoice refund', now()->toDateString(), $createdByUserId
            );
            $settlement = InvoiceSettlement::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'amount_refunded' => $amount,
                'settlement_date' => now()->toDateString(),
                'settlement_type' => 'refund',
                'reference_document_id' => $customerPayment->id,
                'reference_document_type' => Payment::class,
                'notes' => $reason,
            ]);

            // Revert invoice outstanding amount
            $outstandingAfter = min((float) $invoice->total_amount, (float) $invoice->outstanding_amount + $amount);
            $totalRefunded = $refunded + $amount;
            $newStatus = $outstandingAfter <= 0 ? 'paid' : 'partial_paid';

            $invoice->update([
                'outstanding_amount' => $outstandingAfter,
                'status' => $newStatus,
            ]);
            $invoice->order()->update(['status' => $totalRefunded >= $received ? 'refund' : 'partial_refund']);

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
            $receipt = Receipt::create([
                'uid' => (string) Str::ulid(), 'tenant_id' => $invoice->tenant_id, 'company_id' => $invoice->company_id,
                'customer_id' => $invoice->customer_id, 'receipt_number' => $this->generateReceiptNumber($invoice->company_id),
                'receipt_date' => now()->toDateString(), 'amount' => $amount, 'currency_code' => $invoice->currency_code,
                'payment_method' => $paymentMethod, 'account_id' => $accountId,
                'account_type' => $accountId ? ($paymentMethod === 'cash' ? 'cash' : 'bank') : null,
                'description' => 'Customer advance receipt', 'created_by_user_id' => $createdByUserId ?? Auth::id() ?? 1,
            ]);
            $settlement = InvoiceSettlement::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'amount_to_advance' => $amount,
                'settlement_date' => now()->toDateString(),
                'settlement_type' => 'advance',
                'reference_document_id' => $receipt->id,
                'reference_document_type' => Receipt::class,
            ]);

            // Update advance balance
            $newAdvance = (float)$invoice->advance_balance + $amount;
            $invoice->update([
                'advance_balance' => $newAdvance,
            ]);
            $this->recordReceiptLedger($receipt);

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
            $invoice->order()->update(['status' => $newStatus]);

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

    public function generatePaymentNumber(int $companyId): string
    {
        $prefix = 'PAY-' . now()->format('Ymd');
        $last = Payment::where('company_id', $companyId)->where('payment_number', 'LIKE', $prefix . '%')->orderByDesc('payment_number')->first();
        $sequence = $last ? ((int) substr($last->payment_number, -5)) + 1 : 1;
        return $prefix . '-' . str_pad($sequence, 5, '0', STR_PAD_LEFT);
    }
}
