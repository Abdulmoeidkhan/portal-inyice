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

    /**
     * Get invoice aging report
     */
    public function agingReport(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id', auth()->user()->company_id);

        $report = $this->reportService->invoiceAgingReport(
            auth()->user()->tenant_id,
            $companyId
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
            'company_id' => 'nullable|exists:companies,id',
        ]);

        $companyId = $validated['company_id'] ?? auth()->user()->company_id;

        $report = $this->reportService->revenueReport(
            auth()->user()->tenant_id,
            $companyId,
            $validated['from_date'],
            $validated['to_date'],
            $validated['group_by'] ?? 'month'
        );

        return response()->json($report);
    }

    /**
     * Get customer summary report
     */
    public function customerSummaryReport(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id', auth()->user()->company_id);

        $report = $this->reportService->customerSummaryReport(
            auth()->user()->tenant_id,
            $companyId
        );

        return response()->json($report);
    }
}
