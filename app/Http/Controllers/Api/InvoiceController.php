<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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

        $query = Invoice::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', $companyId)
            ->with(['customer', 'order']);

        if ($status) {
            $query->where('status', $status);
        }

        if ($customerId) {
            $query->where('customer_id', $customerId);
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
            ->with(['lines', 'customer', 'order', 'settlements'])
            ->firstOrFail();

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
