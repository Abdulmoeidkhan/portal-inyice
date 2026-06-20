<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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

        $order = Order::findOrFail($validated['order_id']);

        // Check tenant authorization
        if ($order->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

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
        $perPage = $request->query('per_page', 50);
        $status = $request->query('status');
        $customerId = $request->query('customer_id');
        $companyId = $request->query('company_id', auth()->user()->company_id);
        $search = trim((string) $request->query('search', ''));

        $query = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', $companyId)
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

        $invoices = $query->orderByDesc('invoice_date')
            ->paginate($perPage);

        return response()->json($invoices);
    }

    /**
     * Get invoice detail
     */
    public function show(string $uid): JsonResponse
    {
        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('uid', $uid)
            ->with(['lines', 'customer', 'order', 'company', 'settlements'])
            ->firstOrFail();

        return response()->json($invoice);
    }

    public function share(string $uid): JsonResponse
    {
        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)->where('uid', $uid)->firstOrFail();
        if (!$invoice->share_token) $invoice->update(['share_token' => Str::random(64)]);
        return response()->json([
            'share_url' => url('/shared/invoices/' . $invoice->share_token),
            'share_token' => $invoice->share_token,
        ]);
    }

    public function revokeShare(string $uid): JsonResponse
    {
        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)->where('uid', $uid)->firstOrFail();
        $invoice->update(['share_token' => null]);
        return response()->json(['success' => true]);
    }

    public function shared(string $token): JsonResponse
    {
        $invoice = Invoice::where('share_token', $token)
            ->with(['lines', 'customer', 'order', 'company'])
            ->firstOrFail();
        $invoice->makeHidden(['share_token', 'tenant_id', 'company_id', 'customer_id', 'order_id', 'fx_rate_to_base', 'created_at', 'updated_at']);
        $invoice->lines->each->setVisible(['id', 'description', 'quantity', 'unit_price', 'total_price']);
        $invoice->customer?->setVisible(['name', 'email', 'phone', 'address', 'city', 'country_code', 'postal_code']);
        $invoice->order?->setVisible(['order_number', 'booking_reference']);
        $invoice->company?->setVisible(['legal_name', 'display_name', 'email', 'phone', 'address']);
        return response()->json($invoice);
    }

    /**
     * Mark invoice as sent
     */
    public function markAsSent(string $uid): JsonResponse
    {
        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
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
        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('uid', $uid)
            ->firstOrFail();

        if (!in_array($invoice->status, ['draft', 'issued', 'sent'])) {
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
    }

    /**
     * Get invoice aging status
     */
    public function agingStatus(string $uid): JsonResponse
    {
        $invoice = Invoice::where('tenant_id', auth()->user()->tenant_id)
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
