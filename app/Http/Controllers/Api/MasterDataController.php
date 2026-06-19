<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MasterDataController extends Controller
{
    public function customers(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));

        $query = Customer::where('tenant_id', $user->tenant_id)
            ->active()
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return response()->json($query->limit(50)->get(['id', 'uid', 'name', 'email', 'phone', 'currency_code']));
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $this->resolveCompany($request, (int) $user->tenant_id, (int) $user->company_id);

        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'type' => 'nullable|in:B2B,B2C',
            'email' => 'nullable|email|max:200',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:1000',
            'city' => 'nullable|string|max:100',
            'country_code' => 'nullable|string|size:2',
            'currency_code' => 'nullable|string|size:3|exists:currencies,code',
        ]);

        $customer = Customer::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'type' => $validated['type'] ?? 'B2C',
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'country_code' => isset($validated['country_code']) ? strtoupper($validated['country_code']) : null,
            'currency_code' => isset($validated['currency_code']) ? strtoupper($validated['currency_code']) : $company->base_currency_code,
            'is_active' => true,
        ]);

        return response()->json(['success' => true, 'customer' => $customer], 201);
    }

    public function vendors(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));

        $query = Vendor::where('tenant_id', $user->tenant_id)
            ->active()
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return response()->json($query->limit(50)->get(['id', 'uid', 'name', 'email', 'phone', 'currency_code', 'payment_terms']));
    }

    public function storeVendor(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $this->resolveCompany($request, (int) $user->tenant_id, (int) $user->company_id);

        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'type' => 'nullable|in:B2B,B2C',
            'email' => 'nullable|email|max:200',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:1000',
            'city' => 'nullable|string|max:100',
            'country_code' => 'nullable|string|size:2',
            'currency_code' => 'nullable|string|size:3|exists:currencies,code',
            'payment_terms' => 'nullable|string|max:100',
        ]);

        $vendor = Vendor::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'type' => $validated['type'] ?? 'B2C',
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'country_code' => isset($validated['country_code']) ? strtoupper($validated['country_code']) : null,
            'currency_code' => isset($validated['currency_code']) ? strtoupper($validated['currency_code']) : $company->base_currency_code,
            'payment_terms' => $validated['payment_terms'] ?? null,
            'is_active' => true,
        ]);

        return response()->json(['success' => true, 'vendor' => $vendor], 201);
    }

    private function resolveCompany(Request $request, int $tenantId, int $defaultCompanyId): Company
    {
        $companyId = (int) ($request->input('company_id') ?: $defaultCompanyId);

        return Company::where('tenant_id', $tenantId)->findOrFail($companyId);
    }
}
