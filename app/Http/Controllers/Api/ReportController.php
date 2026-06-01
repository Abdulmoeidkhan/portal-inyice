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
