<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvoiceDiscount;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderVendorCost;
use App\Models\RefundAllocation;
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
            'order' => $order->fresh(['invoice', 'invoices']),
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
            $query->where(function ($statusQuery) use ($status): void {
                $statusQuery->where('status', $status);

                if (in_array($status, ['refund_request', 'partial_refund', 'refund'], true)) {
                    $statusQuery->orWhereHas('order', fn ($orderQuery) => $orderQuery->where('status', $status));
                }
            });
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
            ->map(function (Invoice $invoice): array {
                $isRefundInvoice = in_array((string) $invoice->order?->status, ['refund_request', 'partial_refund', 'refund'], true)
                    && (float) $invoice->total_amount < 0;

                return [
                    ...$invoice->toArray(),
                    'status' => $isRefundInvoice ? $invoice->order->status : $invoice->status,
                    'is_refund_order' => $isRefundInvoice,
                    'is_virtual_invoice' => false,
                    'can_void_invoice' => !$isRefundInvoice && $this->canVoidInvoice($invoice),
                    'can_delete_invoice' => !$isRefundInvoice && $this->canDeleteInvoice($invoice),
                    'can_create_refund_request' => !$isRefundInvoice && $this->canCreateRefundRequest($invoice),
                    ...$this->refundDeletionStateForInvoice($invoice),
                    'sort_date' => optional($invoice->invoice_date)->toDateString() ?? optional($invoice->created_at)->toDateString(),
                    'sort_id' => $invoice->id,
                ];
            })->all());

        $refundOrders = collect();
        $refundTotal = 0;
        if (!$status || in_array($status, ['partial_refund', 'refund'], true)) {
            $refundQuery = Order::where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereIn('status', ['partial_refund', 'refund'])
                ->whereDoesntHave('invoice', fn ($invoice) => $invoice->whereNotIn('status', ['void', 'cancel']))
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
                    'is_virtual_invoice' => true,
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
            ->first();

        if (!$invoice) {
            $refundOrder = $this->virtualRefundInvoiceOrder($uid);

            if ($refundOrder) {
                return response()->json($this->virtualRefundInvoiceResponse($refundOrder, $user));
            }

            abort(404, 'No query results for model [App\\Models\\Invoice].');
        }

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

    private function virtualRefundInvoiceOrder(string $uid): ?Order
    {
        return Order::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->where('uid', $uid)
            ->whereIn('status', ['refund_request', 'partial_refund', 'refund'])
            ->whereDoesntHave('invoice', fn ($invoice) => $invoice->whereNotIn('status', ['void', 'cancel']))
            ->with(['items', 'customer', 'company', 'vendor'])
            ->first();
    }

    private function virtualRefundInvoiceResponse(Order $order, $user): array
    {
        if ($this->shouldHideCostProfit($user)) {
            $order->setAttribute('meta', $this->stripVoucherCostProfit($order->meta ?? []));
        }

        $total = (float) $order->total_amount;
        $invoice = [
            'id' => 'refund-order-' . $order->id,
            'uid' => $order->uid,
            'invoice_number' => 'Refund ' . $order->order_number,
            'invoice_date' => optional($order->updated_at)->toDateString(),
            'due_date' => null,
            'currency_code' => $order->currency_code,
            'subtotal' => $total,
            'tax_amount' => 0,
            'total_amount' => $total,
            'outstanding_amount' => 0,
            'advance_balance' => 0,
            'status' => $order->status,
            'notes' => $order->notes,
            'customer' => $order->customer?->toArray(),
            'company' => $order->company?->toArray(),
            'order' => $order->toArray(),
            'lines' => $order->items->map(fn ($item): array => [
                'id' => $item->id,
                'uid' => $item->uid,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'total_price' => $item->total_price,
            ])->values()->all(),
            'discounts' => [],
            'settlements' => [],
            'is_refund_order' => true,
            'is_virtual_invoice' => true,
            'can_void_invoice' => false,
            'can_delete_invoice' => false,
            'can_create_refund_request' => false,
            ...$this->refundDeletionStateForOrder($order),
        ];

        return $this->normalizeResponseText($invoice);
    }

    private function refundDeletionStateForInvoice(Invoice $invoice): array
    {
        return $this->refundDeletionStateForOrder($this->relatedRefundOrderForInvoice($invoice));
    }

    private function refundDeletionStateForOrder(?Order $refundOrder): array
    {
        return [
            'has_refund_order' => $refundOrder !== null,
            'refund_order_uid' => $refundOrder?->uid,
            'refund_order_number' => $refundOrder?->order_number,
            'can_delete_refund' => $refundOrder !== null && !$this->refundOrderHasAllocations($refundOrder),
        ];
    }

    private function relatedRefundOrderForInvoice(Invoice $invoice): ?Order
    {
        $order = $invoice->relationLoaded('order') ? $invoice->order : $invoice->order()->first();
        if (!$order) {
            return null;
        }

        if (in_array((string) $order->status, ['refund_request', 'partial_refund', 'refund'], true)) {
            return $order;
        }

        return $this->refundOrderForOriginalOrder($order);
    }

    private function refundOrderForOriginalOrder(Order $order): ?Order
    {
        return Order::where('tenant_id', $order->tenant_id)
            ->where('company_id', $order->company_id)
            ->whereKeyNot($order->id)
            ->whereIn('status', ['refund_request', 'partial_refund', 'refund'])
            ->where(function ($query) use ($order): void {
                $query->where('booking_reference', $order->order_number)
                    ->orWhere('meta->refund_of_order_id', $order->id)
                    ->orWhere('meta->refund_of_order_uid', $order->uid)
                    ->orWhere('meta->refund_of_order_number', $order->order_number);
            })
            ->latest('id')
            ->first();
    }

    private function refundOrderHasAllocations(Order $refundOrder): bool
    {
        return RefundAllocation::where('tenant_id', $refundOrder->tenant_id)
            ->where('order_id', $refundOrder->id)
            ->exists();
    }

    private function canCreateRefundRequest(Invoice $invoice): bool
    {
        if (in_array((string) $invoice->status, ['void', 'cancel'], true)) {
            return false;
        }

        $order = $invoice->relationLoaded('order') ? $invoice->order : $invoice->order()->first();
        if (!$order || in_array((string) $order->status, ['refund_request', 'partial_refund', 'refund'], true)) {
            return false;
        }

        return !$this->refundOrderExistsFor($order);
    }

    private function canDeleteInvoice(Invoice $invoice): bool
    {
        if (in_array((string) $invoice->status, ['paid', 'partial_paid', 'void', 'cancel'], true)) {
            return false;
        }

        return !$this->invoiceHasPaymentActivity($invoice);
    }

    private function canVoidInvoice(Invoice $invoice): bool
    {
        if (!in_array($invoice->status, ['draft', 'issued', 'sent'], true)) {
            return false;
        }

        if ($this->invoiceHasPaymentActivity($invoice)) {
            return false;
        }

        $order = $invoice->relationLoaded('order') ? $invoice->order : $invoice->order()->first();
        if (!$order || in_array((string) $order->status, ['refund_request', 'partial_refund', 'refund'], true)) {
            return false;
        }

        return !$this->refundOrderExistsFor($order);
    }

    private function invoiceHasPaymentActivity(Invoice $invoice): bool
    {
        return $invoice->settlements()
            ->where('status', 'confirmed')
            ->where(function ($query): void {
                $query->where('amount_received', '>', 0)
                    ->orWhere('amount_refunded', '>', 0)
                    ->orWhere('amount_to_advance', '>', 0);
            })
            ->exists();
    }

    private function refundOrderExistsFor(Order $order): bool
    {
        return Order::where('tenant_id', $order->tenant_id)
            ->where('company_id', $order->company_id)
            ->whereKeyNot($order->id)
            ->whereIn('status', ['refund_request', 'partial_refund', 'refund'])
            ->where(function ($query) use ($order): void {
                $query->where('booking_reference', $order->order_number)
                    ->orWhere('meta->refund_of_order_id', $order->id)
                    ->orWhere('meta->refund_of_order_uid', $order->uid)
                    ->orWhere('meta->refund_of_order_number', $order->order_number);
            })
            ->exists();
    }

    private function voidInvoiceNote(Invoice $invoice, string $timestamp): string
    {
        $invoice->loadMissing(['lines', 'order.vendorCosts.vendor']);
        $order = $invoice->order;
        $currency = $invoice->currency_code ?: $order?->currency_code ?: '';
        $money = fn (mixed $value): string => trim($currency . ' ' . number_format((float) $value, 2, '.', ''));
        $costRows = $order ? $this->costBreakupRows($order, $money) : [];
        $costTotal = $order ? $this->orderCostTotal($order) : 0.0;
        $profit = (float) $invoice->total_amount - $costTotal;
        $lines = [
            "Voided on {$timestamp}. Invoice values were converted to zero.",
            'Previous totals:',
            '- Revenue: ' . $money($invoice->total_amount),
            '- Outstanding: ' . $money($invoice->outstanding_amount),
            '- Cost: ' . $money($costTotal),
            '- Profit: ' . $money($profit),
            'Revenue breakup:',
        ];

        foreach ($invoice->lines as $line) {
            $lines[] = sprintf(
                '- %s | Qty %s | Unit %s | Total %s',
                trim((string) $line->description),
                (string) $line->quantity,
                $money($line->unit_price),
                $money($line->total_price)
            );
        }

        $lines[] = 'Costing breakup:';
        if ($costRows === []) {
            $lines[] = '- No cost rows recorded';
        } else {
            array_push($lines, ...$costRows);
        }

        return implode("\n", $lines);
    }

    private function orderCostTotal(Order $order): float
    {
        $order->loadMissing('vendorCosts');

        if ($order->vendorCosts->isNotEmpty()) {
            return (float) $order->vendorCosts->sum('amount');
        }

        $meta = is_array($order->meta) ? $order->meta : [];
        $total = collect($meta['pricing'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->sum(fn (array $row) => OrderVendorCost::toAmount($row['flight_cost'] ?? null));

        foreach (array_keys(OrderVendorCost::SERVICE_SECTIONS) as $section) {
            $total += collect($meta[$section] ?? [])
                ->filter(fn ($row) => is_array($row))
                ->sum(fn (array $row) => OrderVendorCost::amountFromServiceRow($row));
        }

        return (float) $total;
    }

    private function costBreakupRows(Order $order, callable $money): array
    {
        $order->loadMissing(['vendorCosts.vendor']);

        if ($order->vendorCosts->isNotEmpty()) {
            return $order->vendorCosts
                ->map(fn (OrderVendorCost $row) => sprintf(
                    '- %s | %s | %s',
                    $row->vendor?->name ?: 'Unassigned vendor',
                    str_replace('_', ' ', $row->service_type),
                    $money($row->amount)
                ))
                ->values()
                ->all();
        }

        $meta = is_array($order->meta) ? $order->meta : [];
        $rows = [];

        foreach (($meta['pricing'] ?? []) as $pricing) {
            if (!is_array($pricing)) {
                continue;
            }

            $amount = OrderVendorCost::toAmount($pricing['flight_cost'] ?? null);
            if ($amount != 0.0) {
                $rows[] = sprintf(
                    '- %s | flight | %s',
                    trim((string) ($pricing['vendor_name'] ?? $pricing['pax_name'] ?? 'Flight vendor')),
                    $money($amount)
                );
            }
        }

        foreach (OrderVendorCost::SERVICE_SECTIONS as $section => $serviceType) {
            foreach (($meta[$section] ?? []) as $serviceRow) {
                if (!is_array($serviceRow)) {
                    continue;
                }

                $amount = OrderVendorCost::amountFromServiceRow($serviceRow);
                if ($amount != 0.0) {
                    $rows[] = sprintf(
                        '- %s | %s | %s',
                        trim((string) ($serviceRow['vendor_name'] ?? $serviceRow['visa_vendor'] ?? ucfirst(str_replace('_', ' ', $serviceType)))),
                        str_replace('_', ' ', $serviceType),
                        $money($amount)
                    );
                }
            }
        }

        return $rows;
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

            if ($this->invoiceHasPaymentActivity($invoice)) {
                return response()->json([
                    'error' => 'Remove payments from this invoice before deleting or voiding it.',
                ], 422);
            }

            if (!in_array($invoice->status, ['draft', 'issued', 'sent'], true)) {
                return response()->json([
                    'error' => 'Cannot void invoice with status: ' . $invoice->status,
                ], 422);
            }

            $invoice->loadMissing(['lines', 'order.vendorCosts.vendor']);

            if (!$this->canVoidInvoice($invoice)) {
                return response()->json([
                    'error' => 'Cannot void this invoice because a refund request or refund order already exists.',
                ], 422);
            }

            $existingNotes = trim((string) $invoice->notes);
            $voidNote = $this->voidInvoiceNote($invoice, now()->format('Y-m-d H:i:s'));

            $invoice->lines()->update([
                'unit_price' => 0,
                'total_price' => 0,
            ]);
            $this->invoiceService->releaseReceiptAllocations($invoice);

            $invoice->update([
                'subtotal' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'outstanding_amount' => 0,
                'advance_balance' => 0,
                'status' => 'void',
                'notes' => $existingNotes !== '' ? $existingNotes . "\n" . $voidNote : $voidNote,
            ]);
            $invoice->order()->update(['status' => 'void']);

            return response()->json([
                'success' => true,
                'invoice' => $invoice->fresh(['lines']),
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

            if (!$this->canDeleteInvoice($invoice)) {
                return response()->json([
                    'error' => 'Remove payments from this invoice before deleting or voiding it.',
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
            $this->invoiceService->releaseReceiptAllocations($invoice);
            $invoice->order()->update(['status' => 'cancel']);

            return response()->json([
                'success' => true,
                'invoice' => $invoice->fresh(),
            ]);
        });
    }

    public function deleteRefund(string $uid, Request $request): JsonResponse
    {
        $user = $request->user();

        return DB::transaction(function () use ($uid, $user): JsonResponse {
            $invoice = Invoice::where('tenant_id', $user->tenant_id)
                ->where('company_id', $user->company_id)
                ->where('uid', $uid)
                ->with('order')
                ->first();

            $refundOrder = $invoice
                ? $this->relatedRefundOrderForInvoice($invoice)
                : $this->virtualRefundInvoiceOrder($uid);

            if (!$refundOrder) {
                return response()->json([
                    'error' => 'No refund request or refund order exists for this invoice.',
                ], 404);
            }

            $refundOrder = Order::where('tenant_id', $user->tenant_id)
                ->where('company_id', $user->company_id)
                ->whereKey($refundOrder->id)
                ->whereIn('status', ['refund_request', 'partial_refund', 'refund'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->refundOrderHasAllocations($refundOrder)) {
                return response()->json([
                    'error' => 'Delete customer refund payments and vendor refund receipts before deleting this refund.',
                ], 422);
            }

            $refundInvoices = $refundOrder->invoices()
                ->with('settlements')
                ->get();

            foreach ($refundInvoices as $refundInvoice) {
                if ($this->invoiceHasPaymentActivity($refundInvoice)) {
                    return response()->json([
                        'error' => 'Remove payments from this refund invoice before deleting this refund.',
                    ], 422);
                }
            }

            $deletedInvoiceNumbers = $refundInvoices->pluck('invoice_number')->filter()->values()->all();
            foreach ($refundInvoices as $refundInvoice) {
                $refundInvoice->delete();
            }

            $refundOrderNumber = $refundOrder->order_number;
            $refundOrder->forceDelete();

            return response()->json([
                'success' => true,
                'message' => trim('Refund ' . $refundOrderNumber . ' deleted.'),
                'refund_order_number' => $refundOrderNumber,
                'deleted_invoice_numbers' => $deletedInvoiceNumbers,
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
