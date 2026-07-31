<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StatementService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class StatementController extends Controller
{
    public function __construct(private StatementService $statementService)
    {
    }

    /**
     * Get customer statement
     */
    public function allCustomerStatement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $statement = $this->statementService->customerStatement(
            (int) auth()->user()->tenant_id,
            (int) auth()->user()->company_id,
            null,
            $validated['from_date'] ?? null,
            $validated['to_date'] ?? null
        );

        return response()->json($statement);
    }

    public function customerStatement(int $customerId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $statement = $this->statementService->customerStatement(
            (int) auth()->user()->tenant_id,
            (int) auth()->user()->company_id,
            $customerId,
            $validated['from_date'] ?? null,
            $validated['to_date'] ?? null
        );

        return response()->json($statement);
    }

    /**
     * Get vendor statement
     */
    public function vendorStatement(int $vendorId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $statement = $this->statementService->vendorStatement(
            (int) auth()->user()->tenant_id,
            (int) auth()->user()->company_id,
            $vendorId,
            $validated['from_date'] ?? null,
            $validated['to_date'] ?? null
        );

        return response()->json($statement);
    }
}
