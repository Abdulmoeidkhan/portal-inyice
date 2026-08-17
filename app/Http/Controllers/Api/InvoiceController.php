<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvoiceDiscount;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    private const PRICING_COST_PROFIT_FIELDS = [
        'flight_cost',
        'flight_profit',
        'hotel_cost',
        'hotel_profit',
        'visa_cost',
        'visa_profit',
        'transfer_cost',
        'transfer_profit',
        'city_tour_ziarat_cost',
        'city_tour_ziarat_profit',
        'other_service_cost',
        'other_service_profit',
    ];

    private const SERVICE_COST_PROFIT_FIELDS = ['cost', 'profit'];

    public function __construct(private InvoiceService $invoiceService)
    {
    }

    /**
     * Create invoice from order
     */
    public function createFromOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'invoice_date' => 'nullable|date',
        ]);

        $order = Order::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->findOrFail($validated['order_id']);

        $invoice = $this->invoiceService->createFromOrder($order, $validated['invoice_date'] ?? null);

        return response()->json([
            'success' => true,
            'invoice' => $invoice->load('lines'),
        ], 201);
    }

    /**
     * List invoices
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 50)));
        $page = max(1, (int) $request->query('page', 1));
        $status = $request->query('status');
        $customerId = $request->query('customer_id');
        $companyId = auth()->user()->company_id;
        $tenantId = auth()->user()->tenant_id;
        $search = trim((string) $request->query('search', ''));

        $query = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancel')
            ->with(['customer', 'order.createdBy']);

        if ($status) {
            $query->where('status', $status);
        }

        if ($customerId) {
            $query->where('customer_id', $customerId);
        }

        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('order', function ($orderQuery) use ($search) {
                        $orderQuery->where('order_number', 'like', "%{$search}%");
                    });
            });
        }

        $candidateLimit = $page * $perPage;
        $invoiceTotal = (clone $query)->count();
        $invoiceRows = collect((clone $query)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->limit($candidateLimit)
            ->get()
            ->map(fn (Invoice $invoice): array => [
            ...$invoice->toArray(),
            'is_refund_order' => false,
            'sort_date' => optional($invoice->invoice_date)->toDateString() ?? optional($invoice->created_at)->toDateString(),
            'sort_id' => $invoice->id,
        ])->all());

        $refundOrders = collect();
        $refundTotal = 0;
        if (!$status || in_array($status, ['partial_refund', 'refund'], true)) {
            $refundQuery = Order::where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereIn('status', ['partial_refund', 'refund'])
                ->when($customerId, fn ($orderQuery) => $orderQuery->where('customer_id', $customerId))
                ->with(['customer', 'invoice', 'items', 'vendor', 'createdBy'])
                ->when($search !== '', function ($orderQuery) use ($search): void {
                    $orderQuery->where(function ($searchQuery) use ($search): void {
                        $searchQuery->where('order_number', 'like', "%{$search}%")
                            ->orWhere('booking_reference', 'like', "%{$search}%")
                            ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', "%{$search}%"));
                    });
                });

            $refundTotal = (clone $refundQuery)->count();
            $refundOrders = $refundQuery
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit($candidateLimit)
                ->get()
                ->map(fn (Order $order): array => [
                    'id' => 'refund-order-' . $order->id,
                    'uid' => $order->uid,
                    'invoice_number' => 'Refund ' . $order->order_number,
                    'customer_id' => $order->customer_id,
                    'order_id' => $order->id,
                    'invoice_date' => optional($order->updated_at)->toDateString(),
                    'due_date' => null,
                    'currency_code' => $order->currency_code,
                    'subtotal' => $order->total_amount,
                    'tax_amount' => 0,
                    'total_amount' => $order->total_amount,
                    'outstanding_amount' => 0,
                    'status' => $order->status,
                    'customer' => $order->customer?->toArray(),
                    'order' => $order->toArray(),
                    'is_refund_order' => true,
                    'sort_date' => optional($order->updated_at)->toDateString() ?? optional($order->created_at)->toDateString(),
                    'sort_id' => $order->id,
                ]);
        }

        $rows = $invoiceRows
            ->merge($refundOrders)
            ->sortByDesc(fn (array $row) => ($row['sort_date'] ?? '') . '-' . str_pad((string) ($row['sort_id'] ?? 0), 16, '0', STR_PAD_LEFT))
            ->values()
            ->map(function (array $row): array {
                unset($row['sort_date'], $row['sort_id']);
                return $row;
            });

        $invoices = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $invoiceTotal + $refundTotal,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json($invoices);
    }

    /**
     * Get invoice detail
     */
    public function show(string $uid): JsonResponse
    {
        $user = auth()->user();
        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->where('uid', $uid)
            ->with($user?->hasRole('sales') === true
                ? ['lines', 'customer', 'order', 'company']
                : ['lines', 'discounts.createdBy:id,name', 'discounts.updatedBy:id,name', 'customer', 'order', 'company', 'settlements.referenceDocument'])
            ->firstOrFail();

        if ($user?->hasRole('sales') === true) {
            $invoice->setRelation('settlements', collect());
        }

        if ($this->shouldHideCostProfit($user)) {
            $this->stripOrderCostProfitForSales($invoice);
        }

        return response()->json($this->normalizeResponseText($invoice->toArray()));
    }

    public function discounts(string $uid): JsonResponse
    {
        $invoice = $this->invoiceForCurrentUser($uid);

        return response()->json([
            'data' => $invoice->discounts()
                ->with(['createdBy:id,name', 'updatedBy:id,name'])
                ->latest('id')
                ->get()
                ->map(fn (InvoiceDiscount $discount) => $this->serializeDiscount($discount))
                ->values(),
            'summary' => [
                'total' => (float) $invoice->discounts()->sum('amount'),
                'invoice_total' => (float) $invoice->total_amount,
                'outstanding_amount' => (float) $invoice->outstanding_amount,
            ],
        ]);
    }

    public function share(string $uid): JsonResponse
    {
        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->where('uid', $uid)
            ->firstOrFail();
        if (!$invoice->share_token) $invoice->update(['share_token' => Str::random(64)]);
        return response()->json([
            'share_url' => url('/shared/invoices/' . $invoice->share_token),
            'share_token' => $invoice->share_token,
        ]);
    }

    public function revokeShare(string $uid): JsonResponse
    {
        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->where('uid', $uid)
            ->firstOrFail();
        $invoice->update(['share_token' => null]);
        return response()->json(['success' => true]);
    }

    public function shared(string $token): JsonResponse
    {
        $invoice = Invoice::where('share_token', $token)
            ->with(['lines', 'customer', 'order', 'company', 'settlements.referenceDocument'])
            ->firstOrFail();
        $invoice->makeHidden(['share_token', 'tenant_id', 'company_id', 'customer_id', 'order_id', 'fx_rate_to_base', 'created_at', 'updated_at']);
        $invoice->lines->each(function ($line): void {
            $line->description = $this->publicInvoiceLineDescription($line->description);
            $line->setVisible(['id', 'description', 'quantity', 'unit_price', 'total_price']);
        });
        $invoice->settlements->each(function ($settlement): void {
            $settlement->setVisible(['id', 'amount_received', 'amount_refunded', 'amount_to_advance', 'settlement_date', 'settlement_type', 'reference_document', 'notes']);
            $settlement->referenceDocument?->setVisible([
                'receipt_number',
                'receipt_date',
                'payment_number',
                'payment_date',
                'amount',
                'currency_code',
                'payment_method',
                'reference_number',
                'description',
            ]);
        });
        $invoice->customer?->setVisible(['name', 'email', 'phone', 'address', 'city', 'country_code', 'postal_code']);
        $invoice->order?->setVisible(['order_number', 'booking_reference']);
        $invoice->company?->setVisible(['legal_name', 'display_name', 'email', 'phone', 'address', 'logo_url', 'footer_logo_url', 'is_paid', 'sales_can_edit_cost']);
        return response()->json($this->normalizeResponseText($invoice->toArray()));
    }

    private function shouldHideCostProfit($user): bool
    {
        return $user?->hasRole('sales') === true && $user?->company()->value('sales_can_edit_cost') !== true;
    }

    private function stripOrderCostProfitForSales(Invoice $invoice): void
    {
        $order = $invoice->order;
        if (!$order) {
            return;
        }

        $order->setAttribute('meta', $this->stripVoucherCostProfit($order->meta ?? []));
    }

    private function stripVoucherCostProfit(array $voucher): array
    {
        foreach (($voucher['pricing'] ?? []) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach (self::PRICING_COST_PROFIT_FIELDS as $field) {
                unset($row[$field]);
            }

            $voucher['pricing'][$index] = $row;
        }

        foreach (['hotels', 'transfers', 'city_tours', 'visa', 'other_services'] as $section) {
            foreach (($voucher[$section] ?? []) as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                foreach (self::SERVICE_COST_PROFIT_FIELDS as $field) {
                    unset($row[$field]);
                }

                $voucher[$section][$index] = $row;
            }
        }

        return $voucher;
    }

    private function publicInvoiceLineDescription(?string $description): string
    {
        $clean = preg_replace('/\s+Vendor:\s+.+$/i', '', (string) $description) ?? '';
        $clean = preg_replace('/\s+\((?:vendor|supplier)[^)]+\)/i', '', $clean) ?? '';

        return trim(preg_replace('/\s{2,}/', ' ', $clean) ?? '');
    }

    public function discount(string $uid, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->where('uid', $uid)
            ->firstOrFail();

        $updated = $this->invoiceService->applyDiscount($invoice, (float) $validated['amount'], $validated['reason'] ?? null, $request->user()?->id);

        return response()->json([
            'success' => true,
            'invoice' => $updated,
        ]);
    }

    public function storeDiscount(string $uid, Request $request): JsonResponse
    {
        $validated = $this->validatedDiscountPayload($request);

        $invoice = $this->invoiceForCurrentUser($uid);
        $discount = $this->invoiceService->createDiscount(
            $invoice,
            $validated['discount_type'],
            $this->discountInputValue($validated),
            $validated['reason'] ?? null,
            $request->user()?->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Discount added successfully.',
            'discount' => $this->serializeDiscount($discount),
            'invoice' => $invoice->fresh(['lines', 'discounts.createdBy:id,name', 'discounts.updatedBy:id,name', 'settlements.referenceDocument']),
        ], 201);
    }

    public function updateDiscount(string $uid, string $discountUid, Request $request): JsonResponse
    {
        $validated = $this->validatedDiscountPayload($request);

        $invoice = $this->invoiceForCurrentUser($uid);
        $discount = $this->discountForInvoice($invoice, $discountUid);
        $discount = $this->invoiceService->updateDiscount(
            $discount,
            $validated['discount_type'],
            $this->discountInputValue($validated),
            $validated['reason'] ?? null,
            $request->user()?->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Discount updated successfully.',
            'discount' => $this->serializeDiscount($discount),
            'invoice' => $invoice->fresh(['lines', 'discounts.createdBy:id,name', 'discounts.updatedBy:id,name', 'settlements.referenceDocument']),
        ]);
    }

    public function destroyDiscount(string $uid, string $discountUid): JsonResponse
    {
        $invoice = $this->invoiceForCurrentUser($uid);
        $discount = $this->discountForInvoice($invoice, $discountUid);

        $this->invoiceService->deleteDiscount($discount);

        return response()->json([
            'success' => true,
            'message' => 'Discount deleted successfully.',
            'invoice' => $invoice->fresh(['lines', 'discounts.createdBy:id,name', 'discounts.updatedBy:id,name', 'settlements.referenceDocument']),
        ]);
    }

    private function invoiceForCurrentUser(string $uid): Invoice
    {
        return Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->where('uid', $uid)
            ->firstOrFail();
    }

    private function discountForInvoice(Invoice $invoice, string $discountUid): InvoiceDiscount
    {
        return InvoiceDiscount::query()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('company_id', $invoice->company_id)
            ->where('invoice_id', $invoice->id)
            ->where('uid', $discountUid)
            ->firstOrFail();
    }

    private function serializeDiscount(InvoiceDiscount $discount): array
    {
        return [
            'uid' => $discount->uid,
            'discount_type' => $discount->discount_type,
            'percentage' => $discount->percentage === null ? null : (float) $discount->percentage,
            'amount' => (float) $discount->amount,
            'reason' => $discount->reason,
            'created_by' => $discount->createdBy ? [
                'id' => $discount->createdBy->id,
                'name' => $discount->createdBy->name,
            ] : null,
            'updated_by' => $discount->updatedBy ? [
                'id' => $discount->updatedBy->id,
                'name' => $discount->updatedBy->name,
            ] : null,
            'created_at' => optional($discount->created_at)->toISOString(),
            'updated_at' => optional($discount->updated_at)->toISOString(),
        ];
    }

    private function validatedDiscountPayload(Request $request): array
    {
        $validated = $request->validate([
            'discount_type' => ['nullable', 'string', 'in:amount,percentage'],
            'amount' => ['nullable', 'numeric', 'min:0.01', 'required_if:discount_type,amount'],
            'percentage' => ['nullable', 'numeric', 'min:0.0001', 'max:100', 'required_if:discount_type,percentage'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $validated['discount_type'] = $validated['discount_type'] ?? 'amount';

        if ($validated['discount_type'] === 'amount' && !isset($validated['amount'])) {
            throw ValidationException::withMessages(['amount' => 'Discount amount is required.']);
        }

        if ($validated['discount_type'] === 'percentage' && !isset($validated['percentage'])) {
            throw ValidationException::withMessages(['percentage' => 'Discount percentage is required.']);
        }

        return $validated;
    }

    private function discountInputValue(array $validated): float
    {
        return (float) ($validated['discount_type'] === 'percentage'
            ? $validated['percentage']
            : $validated['amount']);
    }

    /**
     * Mark invoice as sent
     */
    public function markAsSent(string $uid): JsonResponse
    {
        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->where('uid', $uid)
            ->firstOrFail();

        $updated = $this->invoiceService->markAsSent($invoice);

        return response()->json([
            'success' => true,
            'invoice' => $updated,
        ]);
    }

    /**
     * Void invoice
     */
    public function void(string $uid): JsonResponse
    {
        return DB::transaction(function () use ($uid): JsonResponse {
            $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
                ->where('company_id', auth()->user()->company_id)
                ->where('uid', $uid)
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($invoice->status, ['draft', 'issued', 'sent'], true)) {
                return response()->json([
                    'error' => 'Cannot void invoice with status: ' . $invoice->status,
                ], 422);
            }

            $invoice->update(['status' => 'void']);
            $invoice->order()->update(['status' => 'void']);

            return response()->json([
                'success' => true,
                'invoice' => $invoice->fresh(),
            ]);
        });
    }

    public function cancel(string $uid, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'invoice_number' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (!Hash::check($validated['password'], $user->password ?? '')) {
            return response()->json([
                'error' => 'Password is incorrect.',
            ], 403);
        }

        return DB::transaction(function () use ($uid, $validated, $user): JsonResponse {
            $invoice = Invoice::where('tenant_id', $user->tenant_id)
                ->where('company_id', $user->company_id)
                ->where('uid', $uid)
                ->lockForUpdate()
                ->firstOrFail();

            if (!hash_equals($invoice->invoice_number, trim((string) $validated['invoice_number']))) {
                return response()->json([
                    'error' => 'Invoice number does not match.',
                ], 422);
            }

            $existingNotes = trim((string) $invoice->notes);
            $cancelNote = sprintf(
                'Deleted from invoices tab on %s by %s after password and invoice number confirmation.',
                now()->format('Y-m-d H:i:s'),
                $user->name
            );

            $invoice->update([
                'status' => 'cancel',
                'notes' => $existingNotes !== '' ? $existingNotes . "\n" . $cancelNote : $cancelNote,
            ]);
            $invoice->order()->update(['status' => 'cancel']);

            return response()->json([
                'success' => true,
                'invoice' => $invoice->fresh(),
            ]);
        });
    }

    /**
     * Get invoice aging status
     */
    public function agingStatus(string $uid): JsonResponse
    {
        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->where('uid', $uid)
            ->firstOrFail();

        $outstanding = $this->invoiceService->calculateOutstanding($invoice);
        $isPaid = $this->invoiceService->isPaid($invoice);
        $isOverdue = $this->invoiceService->isOverdue($invoice);

        return response()->json([
            'invoice_uid' => $invoice->uid,
            'invoice_number' => $invoice->invoice_number,
            'outstanding' => $outstanding,
            'advance' => $invoice->advance_balance,
            'is_paid' => $isPaid,
            'is_overdue' => $isOverdue,
            'days_overdue' => $isOverdue ? now()->diffInDays($invoice->due_date) : 0,
        ]);
    }
}
