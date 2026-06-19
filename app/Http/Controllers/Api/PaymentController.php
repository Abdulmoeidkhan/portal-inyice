<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Vendor;
use App\Models\VendorPaymentAllocation;
use App\Services\PaymentService;
use App\Services\LedgerService;
use App\Services\StatementService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private LedgerService $ledgerService,
        private StatementService $statementService
    ) {
    }

    /**
     * List payments made to vendors.
     */
    public function vendorPayments(Request $request): JsonResponse
    {
        $query = Payment::where('tenant_id', auth()->user()->tenant_id)
            ->with(['vendor:id,name', 'allocations.order:id,order_number']);

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', (int) $request->query('vendor_id'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->where('payment_number', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhereHas('vendor', fn ($vendorQuery) => $vendorQuery->where('name', 'like', "%{$search}%"));
            });
        }

        return response()->json($query->orderByDesc('payment_date')->orderByDesc('id')->paginate(50));
    }

    /**
     * List customer receipts and their invoice allocations.
     */
    public function customerReceipts(Request $request): JsonResponse
    {
        $query = Receipt::where('tenant_id', auth()->user()->tenant_id)
            ->with(['customer:id,name', 'settlements.invoice:id,invoice_number']);

        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->query('customer_id'));
        }

        return response()->json($query->orderByDesc('receipt_date')->orderByDesc('id')->paginate(50));
    }

    /**
     * Return vendor orders with their remaining payable amount.
     */
    public function vendorPayables(int $vendorId): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;
        $vendor = Vendor::where('tenant_id', $tenantId)->findOrFail($vendorId);
        $orders = Order::where('tenant_id', $tenantId)
            ->whereNotIn('status', ['quote', 'cancel', 'void'])
            ->orderBy('created_at')
            ->get()
            ->map(function (Order $order) use ($vendor): array {
                $payable = $this->statementService->vendorPayableAmount($order, $vendor->id);
                $allocated = (float) VendorPaymentAllocation::where('tenant_id', $order->tenant_id)
                    ->where('order_id', $order->id)
                    ->sum('amount');

                return [
                    'id' => $order->id,
                    'uid' => $order->uid,
                    'order_number' => $order->order_number,
                    'booking_reference' => $order->booking_reference,
                    'date' => $order->created_at->toDateString(),
                    'currency_code' => $order->currency_code,
                    'net_amount' => round($payable, 4),
                    'paid_amount' => round($allocated, 4),
                    'outstanding_amount' => round(max(0, $payable - $allocated), 4),
                    'description' => $order->notes,
                ];
            })
            ->filter(fn (array $order) => $order['net_amount'] > 0)
            ->values();

        // Payments created before allocation support are treated as oldest-first.
        $totalPayments = (float) Payment::where('tenant_id', $tenantId)->where('vendor_id', $vendor->id)->sum('amount');
        $totalAllocated = (float) VendorPaymentAllocation::where('tenant_id', $tenantId)
            ->whereHas('payment', fn ($query) => $query->where('vendor_id', $vendor->id))
            ->sum('amount');
        $legacyBalance = max(0, $totalPayments - $totalAllocated);
        $orders = $orders->map(function (array $order) use (&$legacyBalance): array {
            $applied = min($legacyBalance, $order['outstanding_amount']);
            $order['paid_amount'] = round($order['paid_amount'] + $applied, 4);
            $order['outstanding_amount'] = round($order['outstanding_amount'] - $applied, 4);
            $legacyBalance -= $applied;
            return $order;
        })->filter(fn (array $order) => $order['outstanding_amount'] > 0)->values();

        return response()->json([
            'vendor' => $vendor->only(['id', 'uid', 'name', 'currency_code']),
            'data' => $orders,
            'outstanding_total' => round((float) $orders->sum('outstanding_amount'), 4),
        ]);
    }

    /**
     * Record a payment made to a vendor.
     */
    public function recordVendorPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|integer|exists:vendors,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,check,card',
            'payment_date' => 'nullable|date',
            'narration' => 'nullable|string|max:1000',
            'reference_number' => 'nullable|string|max:100',
            'account_id' => 'nullable|integer',
            'account_type' => 'nullable|in:cash,bank',
            'allocations' => 'nullable|array|min:1',
            'allocations.*.order_id' => 'required|integer|distinct|exists:orders,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ]);

        $vendor = Vendor::where('tenant_id', auth()->user()->tenant_id)
            ->with('company')
            ->findOrFail((int) $validated['vendor_id']);
        $expectedAccountType = $validated['payment_method'] === 'cash' ? 'cash' : 'bank';
        if (($validated['account_id'] ?? null) && (
            ($validated['account_type'] ?? null) !== $expectedAccountType
            || !$this->accountIsValid($validated['account_id'], $validated['payment_method'], $vendor->company_id, $vendor->currency_code ?: $vendor->company->base_currency_code)
        )) {
            return response()->json(['error' => 'The selected account is unavailable for this company, currency, or payment method.'], 422);
        }
        $statement = $this->statementService->vendorStatement(
            (int) auth()->user()->tenant_id,
            $vendor->id
        );
        $outstanding = max(0, (float) $statement['summary']['outstanding_balance']);

        $allocations = collect($validated['allocations'] ?? []);
        if ($allocations->isNotEmpty()) {
            if (abs((float) $allocations->sum('amount') - (float) $validated['amount']) > 0.0001) {
                return response()->json(['error' => 'The payment amount must equal the allocation total.'], 422);
            }

            $payables = collect($this->vendorPayables($vendor->id)->getData(true)['data'])->keyBy('id');
            foreach ($allocations as $allocation) {
                $payable = $payables->get((int) $allocation['order_id']);
                if (!$payable || (float) $allocation['amount'] > (float) $payable['outstanding_amount']) {
                    return response()->json(['error' => 'An allocation exceeds the selected order balance.'], 422);
                }
            }
        }

        if ((float) $validated['amount'] > $outstanding) {
            return response()->json([
                'error' => 'Vendor payment cannot exceed the outstanding payable balance.',
            ], 422);
        }

        $payment = $this->paymentService->recordVendorPayment(
            vendor: $vendor,
            amount: (float) $validated['amount'],
            paymentMethod: $validated['payment_method'],
            accountId: $validated['account_id'] ?? null,
            accountType: $validated['account_type'] ?? null,
            referenceNumber: $validated['reference_number'] ?? '',
            createdByUserId: auth()->id(),
            paymentDate: $validated['payment_date'] ?? null,
            narration: $validated['narration'] ?? null,
            allocations: $allocations->map(fn (array $allocation) => [
                'order_id' => (int) $allocation['order_id'],
                'amount' => (float) $allocation['amount'],
            ])->all(),
        );

        return response()->json([
            'success' => true,
            'payment' => $payment,
            'outstanding_balance' => $outstanding - (float) $payment->amount,
        ], 201);
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
            'account_id' => 'nullable|integer',
            'reference_number' => 'nullable|string|max:100',
            'narration' => 'nullable|string|max:1000',
            'payment_date' => 'nullable|date',
        ]);

        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('uid', $validated['invoice_uid'])
            ->firstOrFail();

        if (!$this->accountIsValid($validated['account_id'] ?? null, $validated['payment_method'], $invoice->company_id, $invoice->currency_code)) {
            return response()->json(['error' => 'The selected account is unavailable for this company, currency, or receipt method.'], 422);
        }

        if ((float) $validated['amount'] > (float) $invoice->outstanding_amount) {
            return response()->json([
                'error' => 'Payment cannot exceed the outstanding invoice balance.',
            ], 422);
        }

        if (in_array($invoice->status, ['paid', 'void'], true)) {
            return response()->json([
                'error' => 'Payments cannot be recorded against a paid or void invoice.',
            ], 422);
        }

        $settlement = $this->paymentService->recordPayment(
            invoice: $invoice,
            amount: (float)$validated['amount'],
            paymentMethod: $validated['payment_method'],
            accountId: $validated['account_id'] ?? null,
            referenceNumber: $validated['reference_number'] ?? '',
            createdByUserId: auth()->id(),
            paymentDate: $validated['payment_date'] ?? null,
            narration: $validated['narration'] ?? null,
        );

        return response()->json([
            'success' => true,
            'settlement' => $settlement,
            'invoice' => $invoice->fresh(),
        ], 201);
    }

    /**
     * Record one receipt and allocate exact amounts across multiple invoices.
     */
    public function recordBulkPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_uids' => 'required_without:allocations|array|min:2',
            'invoice_uids.*' => 'required|string|distinct|exists:invoices,uid',
            'allocations' => 'required_without:invoice_uids|array|min:2',
            'allocations.*.invoice_uid' => 'required|string|distinct|exists:invoices,uid',
            'allocations.*.amount' => 'required|numeric|min:0.01',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,check,card',
            'account_id' => 'nullable|integer',
            'reference_number' => 'nullable|string|max:100',
            'narration' => 'nullable|string|max:1000',
            'payment_date' => 'nullable|date',
        ]);

        $requestedAllocations = collect($validated['allocations'] ?? []);
        $invoiceUids = $requestedAllocations->isNotEmpty()
            ? $requestedAllocations->pluck('invoice_uid')->all()
            : $validated['invoice_uids'];
        $invoices = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->whereIn('uid', $invoiceUids)
            ->whereNotIn('status', ['paid', 'void'])
            ->where('outstanding_amount', '>', 0)
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();

        if ($invoices->count() !== count($invoiceUids)) {
            return response()->json([
                'error' => 'One or more selected invoices are unavailable for payment.',
            ], 422);
        }

        if ($invoices->pluck('customer_id')->unique()->count() !== 1) {
            return response()->json(['error' => 'Bulk payment invoices must belong to the same customer.'], 422);
        }

        if ($invoices->pluck('company_id')->unique()->count() !== 1) {
            return response()->json(['error' => 'Bulk payment invoices must belong to the same company.'], 422);
        }

        if ($invoices->pluck('currency_code')->unique()->count() !== 1) {
            return response()->json(['error' => 'Bulk payment invoices must use the same currency.'], 422);
        }

        $firstInvoice = $invoices->first();
        if (!$this->accountIsValid($validated['account_id'] ?? null, $validated['payment_method'], $firstInvoice->company_id, $firstInvoice->currency_code)) {
            return response()->json(['error' => 'The selected account is unavailable for this company, currency, or receipt method.'], 422);
        }

        if ((float) $validated['amount'] > (float) $invoices->sum('outstanding_amount')) {
            return response()->json([
                'error' => 'Payment cannot exceed the selected outstanding balance.',
            ], 422);
        }

        if ($requestedAllocations->isNotEmpty()) {
            if (abs((float) $requestedAllocations->sum('amount') - (float) $validated['amount']) > 0.0001) {
                return response()->json(['error' => 'The receipt amount must equal the allocation total.'], 422);
            }
            $balances = $invoices->keyBy('uid');
            foreach ($requestedAllocations as $allocation) {
                if ((float) $allocation['amount'] > (float) $balances[$allocation['invoice_uid']]->outstanding_amount) {
                    return response()->json(['error' => 'An allocation exceeds the selected invoice balance.'], 422);
                }
            }
        }

        $result = $this->paymentService->recordBulkPayment(
            invoices: $invoices,
            amount: (float) $validated['amount'],
            paymentMethod: $validated['payment_method'],
            accountId: $validated['account_id'] ?? null,
            referenceNumber: $validated['reference_number'] ?? '',
            createdByUserId: auth()->id(),
            paymentDate: $validated['payment_date'] ?? null,
            narration: $validated['narration'] ?? null,
            requestedAllocations: $requestedAllocations->mapWithKeys(
                fn (array $allocation) => [$allocation['invoice_uid'] => (float) $allocation['amount']]
            )->all(),
        );

        return response()->json([
            'success' => true,
            'receipt' => $result['receipt'],
            'settlements' => $result['settlements'],
            'allocations' => $result['allocations'],
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

    private function accountIsValid(?int $accountId, string $method, int $companyId, string $currencyCode): bool
    {
        if (!$accountId) {
            return true;
        }

        $model = $method === 'cash' ? CashAccount::class : BankAccount::class;

        return $model::where('id', $accountId)
            ->where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', $companyId)
            ->where('currency_code', $currencyCode)
            ->where('is_active', true)
            ->exists();
    }
}
