<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\RefundAllocation;
use App\Models\Vendor;
use App\Models\VendorPaymentAllocation;
use App\Services\PaymentService;
use App\Services\LedgerService;
use App\Services\StatementService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    private const CLOSED_INVOICE_STATUSES = ['paid', 'void', 'cancel'];
    private const CANCELLED_FINANCIAL_STATUSES = ['void', 'cancel'];

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
            ->where('company_id', auth()->user()->company_id)
            ->with(['vendor:id,name', 'allocations.order:id,order_number'])
            ->withSum('allocations as allocated_amount', 'amount');

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

        $payments = $query->orderByDesc('payment_date')->orderByDesc('id')->paginate(50);
        $payments->getCollection()->transform(function (Payment $payment): Payment {
            $payment->remaining_amount = round(max(0, (float) $payment->amount - (float) ($payment->allocated_amount ?? 0)), 4);
            return $payment;
        });

        return response()->json($payments);
    }

    /**
     * List customer receipts and their invoice allocations.
     */
    public function customerReceipts(Request $request): JsonResponse
    {
        $query = Receipt::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->whereNotNull('customer_id')
            ->with(['customer:id,name', 'settlements.invoice:id,invoice_number'])
            ->withSum('settlements as allocated_amount', 'amount_received');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->query('customer_id'));
        }

        $receipts = $query->orderByDesc('receipt_date')->orderByDesc('id')->paginate(50);
        $receipts->getCollection()->transform(function (Receipt $receipt): Receipt {
            $receipt->remaining_amount = round(max(0, (float) $receipt->amount - (float) ($receipt->allocated_amount ?? 0)), 4);
            return $receipt;
        });

        return response()->json($receipts);
    }

    public function vendorReceipts(Request $request): JsonResponse
    {
        $query = Receipt::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->whereNotNull('vendor_id')
            ->with(['vendor:id,name', 'refundAllocations.order:id,order_number,booking_reference'])
            ->withSum('refundAllocations as allocated_refund_amount', 'amount');
        if ($request->filled('vendor_id')) $query->where('vendor_id', (int) $request->query('vendor_id'));
        return response()->json($query->orderByDesc('receipt_date')->orderByDesc('id')->paginate(50));
    }

    public function customerPayments(Request $request): JsonResponse
    {
        $query = Payment::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->whereNotNull('customer_id')
            ->with(['customer:id,name', 'refundAllocations.order:id,order_number,booking_reference'])
            ->withSum('refundAllocations as allocated_refund_amount', 'amount');
        if ($request->filled('customer_id')) $query->where('customer_id', (int) $request->query('customer_id'));
        return response()->json($query->orderByDesc('payment_date')->orderByDesc('id')->paginate(50));
    }

    public function customerRefundAllocations(int $customerId): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;
        $companyId = (int) auth()->user()->company_id;
        $customer = Customer::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with('company:id,base_currency_code')
            ->findOrFail($customerId);

        $orders = $this->customerRefundOrderRows($tenantId, $companyId, $customer);

        return response()->json([
            'customer' => $customer->only(['id', 'uid', 'name', 'currency_code']),
            'data' => $orders,
            'outstanding_total' => round((float) $orders->sum('outstanding_amount'), 4),
        ]);
    }

    public function vendorRefundAllocations(int $vendorId): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;
        $companyId = (int) auth()->user()->company_id;
        $vendor = Vendor::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with('company:id,base_currency_code')
            ->findOrFail($vendorId);

        $orders = $this->vendorRefundOrderRows($tenantId, $companyId, $vendor);

        return response()->json([
            'vendor' => $vendor->only(['id', 'uid', 'name', 'currency_code']),
            'data' => $orders,
            'outstanding_total' => round((float) $orders->sum('outstanding_amount'), 4),
        ]);
    }

    public function recordCustomerRefundPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,check,card',
            'payment_date' => 'nullable|date',
            'account_id' => 'nullable|integer',
            'reference_number' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
            'allocations' => 'required|array|min:1',
            'allocations.*.order_id' => 'required|integer|distinct|exists:orders,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ]);

        $customer = Customer::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->with('company')
            ->findOrFail((int) $validated['customer_id']);
        $currency = $customer->currency_code ?: $customer->company->base_currency_code;

        if (!$this->accountIsValid($validated['account_id'] ?? null, $validated['payment_method'], $customer->company_id, $currency)) {
            return response()->json(['error' => 'The selected account is unavailable for this customer refund payment.'], 422);
        }

        $allocations = collect($validated['allocations']);
        if (abs((float) $allocations->sum('amount') - (float) $validated['amount']) > 0.0001) {
            return response()->json(['error' => 'The customer refund payment amount must equal the allocation total.'], 422);
        }

        $refundOrders = $this->customerRefundOrderRows((int) $customer->tenant_id, (int) $customer->company_id, $customer)->keyBy('id');
        foreach ($allocations as $allocation) {
            $refundOrder = $refundOrders->get((int) $allocation['order_id']);
            if (!$refundOrder || (float) $allocation['amount'] > (float) $refundOrder['outstanding_amount']) {
                return response()->json(['error' => 'An allocation exceeds the selected customer refund balance.'], 422);
            }
        }

        $payment = $this->paymentService->recordCustomerRefundPayment(
            customer: $customer,
            amount: (float) $validated['amount'],
            paymentMethod: $validated['payment_method'],
            accountId: $validated['account_id'] ?? null,
            referenceNumber: $validated['reference_number'] ?? null,
            description: $validated['description'] ?? null,
            paymentDate: $validated['payment_date'] ?? null,
            createdByUserId: auth()->id(),
            allocations: $allocations->map(fn (array $allocation) => [
                'order_id' => (int) $allocation['order_id'],
                'amount' => (float) $allocation['amount'],
            ])->all()
        );

        return response()->json(['success' => true, 'payment' => $payment], 201);
    }

    public function recordCustomerRefundAdjustment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'amount' => 'required|numeric|min:0.01',
            'adjustment_date' => 'nullable|date',
            'description' => 'nullable|string|max:1000',
            'allocations' => 'required|array|min:1',
            'allocations.*.order_id' => 'required|integer|distinct|exists:orders,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
            'invoice_allocations' => 'required|array|min:1',
            'invoice_allocations.*.invoice_uid' => 'required|string|distinct|exists:invoices,uid',
            'invoice_allocations.*.amount' => 'required|numeric|min:0.01',
        ]);

        $customer = Customer::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->with('company')
            ->findOrFail((int) $validated['customer_id']);
        $currency = $customer->currency_code ?: $customer->company->base_currency_code;
        $refundAllocations = collect($validated['allocations']);
        $invoiceAllocations = collect($validated['invoice_allocations']);

        if (abs((float) $refundAllocations->sum('amount') - (float) $validated['amount']) > 0.0001) {
            return response()->json(['error' => 'The customer refund adjustment amount must equal the refund allocation total.'], 422);
        }
        if (abs((float) $invoiceAllocations->sum('amount') - (float) $validated['amount']) > 0.0001) {
            return response()->json(['error' => 'The customer refund adjustment amount must equal the invoice allocation total.'], 422);
        }

        $refundOrders = $this->customerRefundOrderRows((int) $customer->tenant_id, (int) $customer->company_id, $customer)->keyBy('id');
        foreach ($refundAllocations as $allocation) {
            $refundOrder = $refundOrders->get((int) $allocation['order_id']);
            if (!$refundOrder || (float) $allocation['amount'] > (float) $refundOrder['outstanding_amount']) {
                return response()->json(['error' => 'An allocation exceeds the selected customer refund balance.'], 422);
            }
        }

        $invoices = Invoice::where('tenant_id', $customer->tenant_id)
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->where('currency_code', $currency)
            ->whereIn('uid', $invoiceAllocations->pluck('invoice_uid')->all())
            ->whereNotIn('status', self::CLOSED_INVOICE_STATUSES)
            ->where('total_amount', '>', 0)
            ->where('outstanding_amount', '>', 0)
            ->get()
            ->keyBy('uid');

        foreach ($invoiceAllocations as $allocation) {
            $invoice = $invoices->get($allocation['invoice_uid']);
            if (!$invoice || (float) $allocation['amount'] > (float) $invoice->outstanding_amount) {
                return response()->json(['error' => 'An adjustment exceeds the selected invoice balance.'], 422);
            }
        }

        $result = $this->paymentService->recordCustomerRefundAdjustment(
            customer: $customer,
            invoices: $invoices->values(),
            amount: (float) $validated['amount'],
            adjustmentDate: $validated['adjustment_date'] ?? null,
            description: $validated['description'] ?? null,
            createdByUserId: auth()->id(),
            refundAllocations: $refundAllocations->map(fn (array $allocation) => [
                'order_id' => (int) $allocation['order_id'],
                'amount' => (float) $allocation['amount'],
            ])->all(),
            invoiceAllocations: $invoiceAllocations->map(fn (array $allocation) => [
                'invoice_id' => (int) $invoices->get($allocation['invoice_uid'])->id,
                'amount' => (float) $allocation['amount'],
            ])->all()
        );

        return response()->json(['success' => true, 'adjustment' => $result], 201);
    }

    public function recordVendorRefundReceipt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|integer|exists:vendors,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,check,card',
            'receipt_date' => 'nullable|date',
            'account_id' => 'nullable|integer',
            'reference_number' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
            'allocations' => 'required|array|min:1',
            'allocations.*.order_id' => 'required|integer|distinct|exists:orders,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ]);

        $vendor = Vendor::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->with('company')
            ->findOrFail((int) $validated['vendor_id']);
        $currency = $vendor->currency_code ?: $vendor->company->base_currency_code;

        if (!$this->accountIsValid($validated['account_id'] ?? null, $validated['payment_method'], $vendor->company_id, $currency)) {
            return response()->json(['error' => 'The selected account is unavailable for this vendor refund receipt.'], 422);
        }

        $allocations = collect($validated['allocations']);
        if (abs((float) $allocations->sum('amount') - (float) $validated['amount']) > 0.0001) {
            return response()->json(['error' => 'The vendor refund receipt amount must equal the allocation total.'], 422);
        }

        $refundOrders = $this->vendorRefundOrderRows((int) $vendor->tenant_id, (int) $vendor->company_id, $vendor)->keyBy('id');
        foreach ($allocations as $allocation) {
            $refundOrder = $refundOrders->get((int) $allocation['order_id']);
            if (!$refundOrder || (float) $allocation['amount'] > (float) $refundOrder['outstanding_amount']) {
                return response()->json(['error' => 'An allocation exceeds the selected vendor refund balance.'], 422);
            }
        }

        $receipt = $this->paymentService->recordVendorRefundReceipt(
            vendor: $vendor,
            amount: (float) $validated['amount'],
            paymentMethod: $validated['payment_method'],
            accountId: $validated['account_id'] ?? null,
            referenceNumber: $validated['reference_number'] ?? null,
            description: $validated['description'] ?? null,
            receiptDate: $validated['receipt_date'] ?? null,
            createdByUserId: auth()->id(),
            allocations: $allocations->map(fn (array $allocation) => [
                'order_id' => (int) $allocation['order_id'],
                'amount' => (float) $allocation['amount'],
            ])->all()
        );

        return response()->json(['success' => true, 'receipt' => $receipt], 201);
    }

    public function recordCustomerPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id', 'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,check,card', 'payment_date' => 'nullable|date',
            'account_id' => 'nullable|integer', 'reference_number' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
        ]);
        $customer = Customer::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->with('company')
            ->findOrFail($validated['customer_id']);
        $currency = $customer->currency_code ?: $customer->company->base_currency_code;
        if (!$this->accountIsValid($validated['account_id'] ?? null, $validated['payment_method'], $customer->company_id, $currency)) return response()->json(['error' => 'The selected account is unavailable for this customer payment.'], 422);
        $payment = $this->paymentService->recordCustomerPayment($customer, (float) $validated['amount'], $validated['payment_method'], $validated['account_id'] ?? null, $validated['reference_number'] ?? null, $validated['description'] ?? null, $validated['payment_date'] ?? null, auth()->id());
        return response()->json(['success' => true, 'payment' => $payment], 201);
    }

    public function deleteCustomerPayment(string $uid): JsonResponse
    {
        $payment = Payment::where('tenant_id', auth()->user()->tenant_id)->where('company_id', auth()->user()->company_id)->whereNotNull('customer_id')->where('uid', $uid)->firstOrFail();
        $this->paymentService->deleteCustomerPayment($payment);
        return response()->json(['success' => true]);
    }

    public function updateCustomerPayment(Request $request, string $uid): JsonResponse
    {
        $payment = Payment::where('tenant_id', auth()->user()->tenant_id)->where('company_id', auth()->user()->company_id)->whereNotNull('customer_id')->where('uid', $uid)->firstOrFail();
        $validated = $request->validate($this->standaloneTransactionRules());
        if (!$this->accountIsValid($validated['account_id'] ?? null, $validated['payment_method'], $payment->company_id, $payment->currency_code)) return response()->json(['error' => 'The selected account is unavailable.'], 422);
        try { $payment = $this->paymentService->updateCustomerPayment($payment, $validated); }
        catch (\InvalidArgumentException $exception) { return response()->json(['error' => $exception->getMessage()], 422); }
        return response()->json(['success' => true, 'payment' => $payment]);
    }

    public function recordVendorReceipt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|integer|exists:vendors,id', 'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,check,card', 'receipt_date' => 'nullable|date',
            'account_id' => 'nullable|integer', 'reference_number' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
        ]);
        $vendor = Vendor::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->with('company')
            ->findOrFail($validated['vendor_id']);
        $currency = $vendor->currency_code ?: $vendor->company->base_currency_code;
        if (!$this->accountIsValid($validated['account_id'] ?? null, $validated['payment_method'], $vendor->company_id, $currency)) return response()->json(['error' => 'The selected account is unavailable for this vendor receipt.'], 422);
        $receipt = $this->paymentService->recordVendorReceipt($vendor, (float) $validated['amount'], $validated['payment_method'], $validated['account_id'] ?? null, $validated['reference_number'] ?? null, $validated['description'] ?? null, $validated['receipt_date'] ?? null, auth()->id());
        return response()->json(['success' => true, 'receipt' => $receipt], 201);
    }

    public function deleteVendorReceipt(string $uid): JsonResponse
    {
        $receipt = Receipt::where('tenant_id', auth()->user()->tenant_id)->where('company_id', auth()->user()->company_id)->whereNotNull('vendor_id')->where('uid', $uid)->firstOrFail();
        $this->paymentService->deleteVendorReceipt($receipt);
        return response()->json(['success' => true]);
    }

    public function updateVendorReceipt(Request $request, string $uid): JsonResponse
    {
        $receipt = Receipt::where('tenant_id', auth()->user()->tenant_id)->where('company_id', auth()->user()->company_id)->whereNotNull('vendor_id')->where('uid', $uid)->firstOrFail();
        $validated = $request->validate($this->standaloneTransactionRules());
        if (!$this->accountIsValid($validated['account_id'] ?? null, $validated['payment_method'], $receipt->company_id, $receipt->currency_code)) return response()->json(['error' => 'The selected account is unavailable.'], 422);
        $receipt = $this->paymentService->updateVendorReceipt($receipt, $validated);
        return response()->json(['success' => true, 'receipt' => $receipt]);
    }

    public function showCustomerReceipt(string $uid): JsonResponse
    {
        $receipt = Receipt::where('tenant_id', auth()->user()->tenant_id)->where('company_id', auth()->user()->company_id)->where('uid', $uid)
            ->with([
                'company:id,display_name,legal_name,email,phone,address,is_paid,logo_path,footer_logo_path',
                'customer:id,name,email,phone,address,currency_code',
                'settlements.invoice:id,uid,invoice_number,invoice_date,outstanding_amount,total_amount',
            ])
            ->firstOrFail();
        return response()->json($receipt);
    }

    public function updateCustomerReceipt(Request $request, string $uid): JsonResponse
    {
        $receipt = Receipt::where('tenant_id', auth()->user()->tenant_id)->where('company_id', auth()->user()->company_id)->where('uid', $uid)->firstOrFail();
        $validated = $request->validate([
            'date' => 'required|date', 'payment_method' => 'required|in:cash,bank_transfer,check,card',
            'account_id' => 'nullable|integer', 'reference_number' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000', 'allocations' => 'required|array|min:1',
            'allocations.*.invoice_id' => 'required|integer|distinct|exists:invoices,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ]);
        $invoices = Invoice::where('tenant_id', $receipt->tenant_id)
            ->where('company_id', $receipt->company_id)
            ->where('customer_id', $receipt->customer_id)
            ->whereNotIn('status', self::CANCELLED_FINANCIAL_STATUSES)
            ->whereIn('id', collect($validated['allocations'])->pluck('invoice_id'))->get();
        if ($invoices->count() !== count($validated['allocations'])) {
            return response()->json(['error' => 'One or more invoices are unavailable.'], 422);
        }
        if (!$this->accountIsValid($validated['account_id'] ?? null, $validated['payment_method'], $receipt->company_id, $receipt->currency_code)) {
            return response()->json(['error' => 'The selected account is unavailable for this receipt.'], 422);
        }
        try {
            $receipt = $this->paymentService->updateReceipt($receipt, $invoices, $validated);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
        return response()->json(['success' => true, 'receipt' => $receipt]);
    }

    public function allocateCustomerAdvance(Request $request, string $uid): JsonResponse
    {
        $receipt = Receipt::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->whereNotNull('customer_id')
            ->where('uid', $uid)
            ->firstOrFail();

        $validated = $request->validate([
            'date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'allocations' => 'required|array|min:1',
            'allocations.*.invoice_id' => 'required|integer|distinct|exists:invoices,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ]);

        $invoices = Invoice::where('tenant_id', $receipt->tenant_id)
            ->where('company_id', $receipt->company_id)
            ->where('customer_id', $receipt->customer_id)
            ->where('currency_code', $receipt->currency_code)
            ->whereNotIn('status', self::CLOSED_INVOICE_STATUSES)
            ->where('outstanding_amount', '>', 0)
            ->whereIn('id', collect($validated['allocations'])->pluck('invoice_id'))
            ->get();

        if ($invoices->count() !== count($validated['allocations'])) {
            return response()->json(['error' => 'One or more invoices are unavailable for advance allocation.'], 422);
        }

        try {
            $receipt = $this->paymentService->allocateCustomerAdvanceReceipt($receipt, $invoices, $validated);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'receipt' => $receipt]);
    }

    public function deleteCustomerReceipt(string $uid): JsonResponse
    {
        $receipt = Receipt::where('tenant_id', auth()->user()->tenant_id)->where('company_id', auth()->user()->company_id)->where('uid', $uid)->firstOrFail();
        try {
            $this->paymentService->deleteReceipt($receipt);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
        return response()->json(['success' => true]);
    }

    public function showVendorPayment(string $uid): JsonResponse
    {
        $payment = Payment::where('tenant_id', auth()->user()->tenant_id)->where('company_id', auth()->user()->company_id)->where('uid', $uid)
            ->with(['vendor:id,name,currency_code', 'allocations.order:id,order_number,booking_reference'])->firstOrFail();
        return response()->json($payment);
    }

    public function updateVendorPayment(Request $request, string $uid): JsonResponse
    {
        $payment = Payment::where('tenant_id', auth()->user()->tenant_id)->where('company_id', auth()->user()->company_id)->where('uid', $uid)->with('vendor.company')->firstOrFail();
        $validated = $request->validate([
            'date' => 'required|date', 'payment_method' => 'required|in:cash,bank_transfer,check,card',
            'account_id' => 'nullable|integer', 'reference_number' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000', 'allocations' => 'required|array|min:1',
            'allocations.*.order_id' => 'required|integer|distinct|exists:orders,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ]);
        $orders = Order::where('tenant_id', $payment->tenant_id)
            ->where('company_id', $payment->company_id)
            ->whereNotIn('status', self::CANCELLED_FINANCIAL_STATUSES)
            ->whereHas('invoice', fn ($invoice) => $invoice->whereNotIn('status', self::CANCELLED_FINANCIAL_STATUSES))
            ->whereIn('id', collect($validated['allocations'])->pluck('order_id'))
            ->get();
        if ($orders->count() !== count($validated['allocations'])) return response()->json(['error' => 'One or more orders are unavailable.'], 422);
        foreach ($validated['allocations'] as $allocation) {
            $order = $orders->firstWhere('id', (int) $allocation['order_id']);
            $payable = $this->statementService->vendorPayableAmount($order, $payment->vendor_id);
            $allocatedElsewhere = (float) VendorPaymentAllocation::where('order_id', $order->id)
                ->where('payment_id', '!=', $payment->id)
                ->whereHas('payment', fn ($query) => $query
                    ->where('company_id', $payment->company_id)
                    ->where('vendor_id', $payment->vendor_id))
                ->sum('amount');
            if ((float) $allocation['amount'] > max(0, $payable - $allocatedElsewhere)) {
                return response()->json(['error' => 'An allocation exceeds the selected order balance.'], 422);
            }
        }
        $accountType = ($validated['account_id'] ?? null) ? ($validated['payment_method'] === 'cash' ? 'cash' : 'bank') : null;
        if (($validated['account_id'] ?? null) && !$this->accountIsValid($validated['account_id'], $validated['payment_method'], $payment->company_id, $payment->currency_code)) {
            return response()->json(['error' => 'The selected account is unavailable for this payment.'], 422);
        }
        $validated['account_type'] = $accountType;
        $payment = $this->paymentService->updateVendorPayment($payment, $validated);
        return response()->json(['success' => true, 'payment' => $payment]);
    }

    public function allocateVendorAdvance(Request $request, string $uid): JsonResponse
    {
        $payment = Payment::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->whereNotNull('vendor_id')
            ->where('uid', $uid)
            ->firstOrFail();

        $validated = $request->validate([
            'allocations' => 'required|array|min:1',
            'allocations.*.order_id' => 'required|integer|distinct|exists:orders,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ]);

        $orders = Order::where('tenant_id', $payment->tenant_id)
            ->where('company_id', $payment->company_id)
            ->whereNotIn('status', self::CANCELLED_FINANCIAL_STATUSES)
            ->whereHas('invoice', fn ($invoice) => $invoice->whereNotIn('status', self::CANCELLED_FINANCIAL_STATUSES))
            ->whereIn('id', collect($validated['allocations'])->pluck('order_id'))
            ->get();

        if ($orders->count() !== count($validated['allocations'])) {
            return response()->json(['error' => 'One or more orders are unavailable for advance allocation.'], 422);
        }

        $payables = collect($this->vendorPayables($payment->vendor_id, true)->getData(true)['data'])->keyBy('id');
        foreach ($validated['allocations'] as $allocation) {
            $payable = $payables->get((int) $allocation['order_id']);
            if (!$payable || (float) $allocation['amount'] > (float) $payable['outstanding_amount']) {
                return response()->json(['error' => 'An allocation exceeds the selected order balance.'], 422);
            }
        }

        try {
            $payment = $this->paymentService->allocateVendorAdvancePayment($payment, $validated['allocations']);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'payment' => $payment]);
    }

    public function deleteVendorPayment(string $uid): JsonResponse
    {
        $payment = Payment::where('tenant_id', auth()->user()->tenant_id)->where('company_id', auth()->user()->company_id)->where('uid', $uid)->firstOrFail();
        $this->paymentService->deleteVendorPayment($payment);
        return response()->json(['success' => true]);
    }

    /**
     * Return vendor orders with their remaining payable amount.
     */
    public function vendorPayables(int $vendorId, bool $ignoreLegacyBalance = false): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;
        $companyId = (int) auth()->user()->company_id;
        $vendor = Vendor::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($vendorId);
        $orders = Order::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereNotIn('status', self::CANCELLED_FINANCIAL_STATUSES)
            ->whereHas('invoice', fn ($invoice) => $invoice->whereNotIn('status', self::CANCELLED_FINANCIAL_STATUSES))
            ->with('invoice:id,order_id,invoice_date')
            ->orderBy('created_at')
            ->get()
            ->map(function (Order $order) use ($vendor): array {
                $payable = $this->statementService->vendorPayableAmount($order, $vendor->id);
                $allocated = (float) VendorPaymentAllocation::where('tenant_id', $order->tenant_id)
                    ->where('order_id', $order->id)
                    ->whereHas('payment', fn ($query) => $query
                        ->where('company_id', $order->company_id)
                        ->where('vendor_id', $vendor->id))
                    ->sum('amount');

                return [
                    'id' => $order->id,
                    'uid' => $order->uid,
                    'order_number' => $order->order_number,
                    'booking_reference' => $order->booking_reference,
                    'date' => $order->invoice?->invoice_date?->toDateString() ?? $order->created_at->toDateString(),
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
        $totalPayments = (float) Payment::where('tenant_id', $tenantId)->where('company_id', $companyId)->where('vendor_id', $vendor->id)->sum('amount');
        $totalAllocated = (float) VendorPaymentAllocation::where('tenant_id', $tenantId)
            ->whereHas('payment', fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('vendor_id', $vendor->id))
            ->sum('amount');
        if (!$ignoreLegacyBalance && !request()->boolean('ignore_legacy_balance')) {
            $legacyBalance = max(0, $totalPayments - $totalAllocated);
            $orders = $orders->map(function (array $order) use (&$legacyBalance): array {
                $applied = min($legacyBalance, $order['outstanding_amount']);
                $order['paid_amount'] = round($order['paid_amount'] + $applied, 4);
                $order['outstanding_amount'] = round($order['outstanding_amount'] - $applied, 4);
                $legacyBalance -= $applied;
                return $order;
            });
        }

        $orders = $orders->filter(fn (array $order) => $order['outstanding_amount'] > 0)->values();

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
            ->where('company_id', auth()->user()->company_id)
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
            (int) auth()->user()->company_id,
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

        if ($allocations->isNotEmpty() && (float) $validated['amount'] > $outstanding) {
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
    public function recordCustomerReceipt(Request $request): JsonResponse
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
            ->where('company_id', auth()->user()->company_id)
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

        if (in_array($invoice->status, self::CLOSED_INVOICE_STATUSES, true)) {
            return response()->json([
                'error' => 'Payments cannot be recorded against a paid, void, or cancelled invoice.',
            ], 422);
        }

        $settlement = $this->paymentService->recordCustomerReceipt(
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
    public function recordBulkCustomerReceipt(Request $request): JsonResponse
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
            ->where('company_id', auth()->user()->company_id)
            ->whereIn('uid', $invoiceUids)
            ->whereNotIn('status', self::CLOSED_INVOICE_STATUSES)
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

        $result = $this->paymentService->recordBulkCustomerReceipt(
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
            ->where('company_id', auth()->user()->company_id)
            ->where('uid', $validated['invoice_uid'])
            ->firstOrFail();

        if (in_array($invoice->status, self::CANCELLED_FINANCIAL_STATUSES, true)) {
            return response()->json(['error' => 'A void or cancelled invoice cannot be refunded.'], 422);
        }

        try {
            $settlement = $this->paymentService->recordRefund(
                invoice: $invoice,
                amount: (float)$validated['amount'],
                reason: $validated['reason'] ?? '',
                createdByUserId: auth()->id()
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

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
            'invoice_uid' => 'required_without:customer_id|exists:invoices,uid',
            'customer_id' => 'required_without:invoice_uid|integer|exists:customers,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,check,card',
            'account_id' => 'nullable|integer',
            'payment_date' => 'nullable|date',
            'reference_number' => 'nullable|string|max:100',
            'narration' => 'nullable|string|max:1000',
        ]);

        if (!empty($validated['customer_id'])) {
            $customer = Customer::where('tenant_id', auth()->user()->tenant_id)
                ->where('company_id', auth()->user()->company_id)
                ->with('company')
                ->findOrFail((int) $validated['customer_id']);
            $currency = $customer->currency_code ?: $customer->company->base_currency_code;

            if (!$this->accountIsValid($validated['account_id'] ?? null, $validated['payment_method'], $customer->company_id, $currency)) {
                return response()->json(['error' => 'The selected account is unavailable for this customer advance receipt.'], 422);
            }

            $receipt = $this->paymentService->recordCustomerAdvanceReceipt(
                customer: $customer,
                amount: (float) $validated['amount'],
                paymentMethod: $validated['payment_method'],
                accountId: $validated['account_id'] ?? null,
                referenceNumber: $validated['reference_number'] ?? null,
                description: $validated['narration'] ?? null,
                receiptDate: $validated['payment_date'] ?? null,
                createdByUserId: auth()->id()
            );

            return response()->json([
                'success' => true,
                'receipt' => $receipt,
            ], 201);
        }

        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->where('uid', $validated['invoice_uid'])
            ->firstOrFail();

        if (in_array($invoice->status, self::CANCELLED_FINANCIAL_STATUSES, true)) {
            return response()->json(['error' => 'Advance cannot be recorded against a void or cancelled invoice.'], 422);
        }

        if (!$this->accountIsValid($validated['account_id'] ?? null, $validated['payment_method'], $invoice->company_id, $invoice->currency_code)) {
            return response()->json(['error' => 'The selected account is unavailable for this customer advance receipt.'], 422);
        }

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
            ->where('company_id', auth()->user()->company_id)
            ->where('uid', $validated['invoice_uid'])
            ->firstOrFail();

        if (in_array($invoice->status, self::CANCELLED_FINANCIAL_STATUSES, true)) {
            return response()->json(['error' => 'Advance cannot be applied to a void or cancelled invoice.'], 422);
        }

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
            ->where('company_id', auth()->user()->company_id)
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

    private function standaloneTransactionRules(): array
    {
        return [
            'date' => 'required|date', 'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,check,card', 'account_id' => 'nullable|integer',
            'reference_number' => 'nullable|string|max:100', 'description' => 'nullable|string|max:1000',
        ];
    }

    private function customerRefundOrderRows(int $tenantId, int $companyId, Customer $customer)
    {
        $currency = $customer->currency_code ?: $customer->company?->base_currency_code;

        return Order::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->when($currency, fn ($query) => $query->where('currency_code', $currency))
            ->whereIn('status', ['refund_request', 'partial_refund', 'refund'])
            ->where('total_amount', '<', 0)
            ->with('vendor:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(function (Order $order) use ($customer): array {
                $allocated = (float) RefundAllocation::where('tenant_id', $order->tenant_id)
                    ->where('order_id', $order->id)
                    ->where('allocation_type', RefundAllocation::CUSTOMER_PAYMENT)
                    ->where(function ($query) use ($order, $customer): void {
                        $query->whereHas('payment', fn ($paymentQuery) => $paymentQuery
                            ->where('company_id', $order->company_id)
                            ->where('customer_id', $customer->id))
                            ->orWhereNull('payment_id');
                    })
                    ->sum('amount');
                $refundAmount = abs((float) $order->total_amount);

                return [
                    'id' => $order->id,
                    'uid' => $order->uid,
                    'order_number' => $order->order_number,
                    'booking_reference' => $order->booking_reference,
                    'date' => $order->created_at->toDateString(),
                    'currency_code' => $order->currency_code,
                    'vendor_name' => $order->vendor?->name,
                    'refund_amount' => round($refundAmount, 4),
                    'allocated_amount' => round($allocated, 4),
                    'outstanding_amount' => round(max(0, $refundAmount - $allocated), 4),
                    'description' => $order->notes,
                ];
            })
            ->filter(fn (array $order) => $order['outstanding_amount'] > 0)
            ->values();
    }

    private function vendorRefundOrderRows(int $tenantId, int $companyId, Vendor $vendor)
    {
        $currency = $vendor->currency_code ?: $vendor->company?->base_currency_code;

        return Order::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->when($currency, fn ($query) => $query->where('currency_code', $currency))
            ->whereIn('status', ['refund_request', 'partial_refund', 'refund'])
            ->with(['customer:id,name', 'vendorCosts'])
            ->orderBy('created_at')
            ->get()
            ->map(function (Order $order) use ($vendor): array {
                $payable = $this->statementService->vendorPayableAmount($order, $vendor->id);
                if ($payable >= 0) {
                    return [];
                }

                $allocated = (float) RefundAllocation::where('tenant_id', $order->tenant_id)
                    ->where('order_id', $order->id)
                    ->where('allocation_type', RefundAllocation::VENDOR_RECEIPT)
                    ->whereHas('receipt', fn ($query) => $query
                        ->where('company_id', $order->company_id)
                        ->where('vendor_id', $vendor->id))
                    ->sum('amount');
                $refundAmount = abs($payable);

                return [
                    'id' => $order->id,
                    'uid' => $order->uid,
                    'order_number' => $order->order_number,
                    'booking_reference' => $order->booking_reference,
                    'date' => $order->created_at->toDateString(),
                    'currency_code' => $order->currency_code,
                    'customer_name' => $order->customer?->name,
                    'refund_amount' => round($refundAmount, 4),
                    'allocated_amount' => round($allocated, 4),
                    'outstanding_amount' => round(max(0, $refundAmount - $allocated), 4),
                    'description' => $order->notes,
                ];
            })
            ->filter(fn (array $order) => ($order['outstanding_amount'] ?? 0) > 0)
            ->values();
    }
}
