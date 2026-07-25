<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(private ReportService $reportService)
    {
    }

    /**
     * Get all money-out customer and vendor payment records.
     */
    public function paymentReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'counterparty_type' => 'nullable|in:customer,vendor',
            'counterparty_id' => 'nullable|integer|min:1',
            'payment_method' => 'nullable|in:cash,bank_transfer,check,card',
            'search' => 'nullable|string|max:100',
        ]);

        $report = $this->reportService->cashTransactionReport(
            (int) auth()->user()->tenant_id,
            (int) auth()->user()->company_id,
            $validated['from_date'],
            $validated['to_date'],
            'payment',
            $validated['counterparty_type'] ?? null,
            isset($validated['counterparty_id']) ? (int) $validated['counterparty_id'] : null,
            $validated['payment_method'] ?? null,
            isset($validated['search']) ? trim($validated['search']) : null,
        );

        return response()->json($report);
    }

    public function receiptReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'required|date', 'to_date' => 'required|date|after_or_equal:from_date',
            'counterparty_type' => 'nullable|in:customer,vendor',
            'counterparty_id' => 'nullable|integer|min:1',
            'payment_method' => 'nullable|in:cash,bank_transfer,check,card', 'search' => 'nullable|string|max:100',
        ]);
        return response()->json($this->reportService->cashTransactionReport(
            (int) auth()->user()->tenant_id, (int) auth()->user()->company_id,
            $validated['from_date'], $validated['to_date'], 'receipt',
            $validated['counterparty_type'] ?? null,
            isset($validated['counterparty_id']) ? (int) $validated['counterparty_id'] : null,
            $validated['payment_method'] ?? null,
            isset($validated['search']) ? trim($validated['search']) : null,
        ));
    }

    public function cancelledReport(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAllowedAgencyUser = $user?->hasAnyRole(['owner', 'admin']) === true;
        $isAllowedSystemUser = $user?->isSystemUser() === true
            && $user->hasAnyRole(['super-admin', 'inyice-admin', 'support-executive']);

        if (!$isAllowedAgencyUser && !$isAllowedSystemUser) {
            return response()->json(['error' => 'Insufficient permissions for this report'], 403);
        }

        $validated = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'search' => 'nullable|string|max:100',
        ]);

        return response()->json($this->reportService->cancelledInvoiceReport(
            $isAllowedSystemUser ? null : (int) $user->tenant_id,
            $isAllowedSystemUser ? null : (int) $user->company_id,
            $validated['from_date'],
            $validated['to_date'],
            isset($validated['search']) ? trim($validated['search']) : null,
            $isAllowedSystemUser,
        ));
    }

    /**
     * Get invoice aging report
     */
    public function agingReport(Request $request): JsonResponse
    {
        $report = $this->reportService->invoiceAgingReport(
            (int) auth()->user()->tenant_id,
            (int) auth()->user()->company_id
        );

        return response()->json($report);
    }

    /**
     * Get revenue report
     */
    public function revenueReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'group_by' => 'nullable|in:day,week,month,year',
        ]);

        $report = $this->reportService->revenueReport(
            (int) auth()->user()->tenant_id,
            (int) auth()->user()->company_id,
            $validated['from_date'],
            $validated['to_date'],
            $validated['group_by'] ?? 'month'
        );

        return response()->json($report);
    }

    public function profitReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'date_by' => 'nullable|in:creation,invoice,departure,checkin,service',
            'group_by' => 'nullable|in:customer,vendor,staff',
            'entity_id' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:100',
        ]);

        $report = $this->reportService->profitReport(
            (int) auth()->user()->tenant_id,
            (int) auth()->user()->company_id,
            $validated['from_date'],
            $validated['to_date'],
            $validated['date_by'] ?? 'invoice',
            $validated['group_by'] ?? 'customer',
            isset($validated['entity_id']) ? (int) $validated['entity_id'] : null,
            isset($validated['search']) ? trim($validated['search']) : null,
        );

        return response()->json($report);
    }

    /**
     * Get customer summary report
     */
    public function customerSummaryReport(Request $request): JsonResponse
    {
        $report = $this->reportService->customerSummaryReport(
            (int) auth()->user()->tenant_id,
            (int) auth()->user()->company_id
        );

        return response()->json($report);
    }

    public function dashboardUpcoming(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:90',
        ]);

        $user = $request->user();

        return response()->json($this->reportService->dashboardUpcoming(
            (int) $user->tenant_id,
            (int) $user->company_id,
            (int) ($validated['days'] ?? 30),
            $user->hasAnyRole(['admin', 'accounts']),
        ));
    }
}
