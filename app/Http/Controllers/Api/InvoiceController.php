<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InvoiceController extends Controller
{
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
        ]);

        $order = Order::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->findOrFail($validated['order_id']);

        $invoice = $this->invoiceService->createFromOrder($order);

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
            ->with(['customer', 'order']);

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
                ->with(['customer', 'invoice', 'items', 'vendor'])
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
        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->where('uid', $uid)
            ->with(['lines', 'customer', 'order', 'company', 'settlements.referenceDocument'])
            ->firstOrFail();

        return response()->json($invoice);
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
        $invoice->company?->setVisible(['legal_name', 'display_name', 'email', 'phone', 'address', 'logo_url', 'footer_logo_url', 'is_paid']);
        return response()->json($invoice);
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

        $updated = $this->invoiceService->applyDiscount($invoice, (float) $validated['amount'], $validated['reason'] ?? null);

        return response()->json([
            'success' => true,
            'invoice' => $updated,
        ]);
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
