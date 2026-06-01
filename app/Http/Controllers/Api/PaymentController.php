<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\PaymentService;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private LedgerService $ledgerService
    ) {
    }

    /**
     * Record customer payment
     */
    public function recordPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_uid' => 'required|exists:invoices,uid',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,check,card',
            'account_id' => 'nullable|exists:bank_accounts,id',
            'reference_number' => 'nullable|string|max:100',
        ]);

        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('uid', $validated['invoice_uid'])
            ->firstOrFail();

        $settlement = $this->paymentService->recordPayment(
            invoice: $invoice,
            amount: (float)$validated['amount'],
            paymentMethod: $validated['payment_method'],
            accountId: $validated['account_id'] ?? null,
            referenceNumber: $validated['reference_number'] ?? '',
            createdByUserId: auth()->id()
        );

        return response()->json([
            'success' => true,
            'settlement' => $settlement,
            'invoice' => $invoice->fresh(),
        ], 201);
    }

    /**
     * Record refund
     */
    public function recordRefund(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_uid' => 'required|exists:invoices,uid',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
        ]);

        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('uid', $validated['invoice_uid'])
            ->firstOrFail();

        $settlement = $this->paymentService->recordRefund(
            invoice: $invoice,
            amount: (float)$validated['amount'],
            reason: $validated['reason'] ?? '',
            createdByUserId: auth()->id()
        );

        return response()->json([
            'success' => true,
            'settlement' => $settlement,
            'invoice' => $invoice->fresh(),
        ], 201);
    }

    /**
     * Record advance / overpayment
     */
    public function recordAdvance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_uid' => 'required|exists:invoices,uid',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,check,card',
            'account_id' => 'nullable|exists:bank_accounts,id',
        ]);

        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('uid', $validated['invoice_uid'])
            ->firstOrFail();

        $settlement = $this->paymentService->recordAdvance(
            invoice: $invoice,
            amount: (float)$validated['amount'],
            paymentMethod: $validated['payment_method'],
            accountId: $validated['account_id'] ?? null,
            createdByUserId: auth()->id()
        );

        return response()->json([
            'success' => true,
            'settlement' => $settlement,
            'invoice' => $invoice->fresh(),
        ], 201);
    }

    /**
     * Apply advance balance to outstanding
     */
    public function applyAdvance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_uid' => 'required|exists:invoices,uid',
            'advance_amount' => 'required|numeric|min:0.01',
        ]);

        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('uid', $validated['invoice_uid'])
            ->firstOrFail();

        if ((float)$invoice->advance_balance < (float)$validated['advance_amount']) {
            return response()->json([
                'error' => 'Insufficient advance balance',
            ], 422);
        }

        $settlement = $this->paymentService->applyAdvance(
            invoice: $invoice,
            advanceAmount: (float)$validated['advance_amount']
        );

        return response()->json([
            'success' => true,
            'settlement' => $settlement,
            'invoice' => $invoice->fresh(),
        ], 201);
    }

    /**
     * Get payment settlements for invoice
     */
    public function settlements(string $invoiceUid): JsonResponse
    {
        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('uid', $invoiceUid)
            ->firstOrFail();

        $settlements = $invoice->settlements()
            ->orderByDesc('settlement_date')
            ->get();

        return response()->json([
            'invoice_uid' => $invoice->uid,
            'settlements' => $settlements,
        ]);
    }
}
