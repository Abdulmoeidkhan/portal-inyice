<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use App\Services\StatementService;
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
            ->where('company_id', $user->company_id)
            ->active()
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $customers = $query->limit(50)->get([
            'id', 'uid', 'type', 'name', 'email', 'phone', 'address', 'city', 'country_code', 'currency_code',
        ]);
        $outstandingBalances = $this->canSeeOutstanding($user)
            ? $this->customerOutstandingBalances($customers, (int) $user->tenant_id, (int) $user->company_id)
            : [];

        return response()->json($customers
            ->map(fn (Customer $customer) => $this->customerPayload($customer, $outstandingBalances[$customer->id] ?? null))
            ->values());
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

    public function updateCustomer(string $uid, Request $request): JsonResponse
    {
        $user = $request->user();
        $customer = Customer::where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->where('uid', $uid)
            ->firstOrFail();

        if ($this->hasCustomerFinancialLinks($customer)) {
            return response()->json(['error' => 'This customer is attached to financial activity and cannot be updated.'], 422);
        }

        $validated = $request->validate($this->customerRules());

        $customer->update([
            'type' => $validated['type'] ?? 'B2C',
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'country_code' => isset($validated['country_code']) ? strtoupper($validated['country_code']) : null,
            'currency_code' => isset($validated['currency_code']) ? strtoupper($validated['currency_code']) : $customer->currency_code,
        ]);

        return response()->json([
            'success' => true,
            'customer' => $this->customerPayload($customer->fresh()),
        ]);
    }

    public function deleteCustomer(string $uid, Request $request): JsonResponse
    {
        $user = $request->user();
        $customer = Customer::where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->where('uid', $uid)
            ->firstOrFail();

        if ($this->hasCustomerFinancialLinks($customer)) {
            return response()->json(['error' => 'This customer is attached to financial activity and cannot be deleted.'], 422);
        }

        $customer->delete();

        return response()->json(['success' => true, 'message' => 'Customer deleted successfully.']);
    }

    public function vendors(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));

        $query = Vendor::where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->active()
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $vendors = $query->limit(50)->get([
            'id', 'uid', 'type', 'name', 'email', 'phone', 'address', 'city', 'country_code', 'currency_code', 'payment_terms',
        ]);
        $outstandingBalances = $this->canSeeOutstanding($user)
            ? $this->vendorOutstandingBalances($vendors, (int) $user->tenant_id, (int) $user->company_id)
            : [];

        return response()->json($vendors
            ->map(fn (Vendor $vendor) => $this->vendorPayload($vendor, $outstandingBalances[$vendor->id] ?? null))
            ->values());
    }

    public function staff(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));

        $query = User::where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->where('is_active', true)
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json($query->limit(50)->get(['id', 'uid', 'name', 'email']));
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

    public function updateVendor(string $uid, Request $request): JsonResponse
    {
        $user = $request->user();
        $vendor = Vendor::where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->where('uid', $uid)
            ->firstOrFail();

        if ($this->hasVendorFinancialLinks($vendor)) {
            return response()->json(['error' => 'This vendor is attached to financial activity and cannot be updated.'], 422);
        }

        $validated = $request->validate([
            ...$this->customerRules(),
            'payment_terms' => 'nullable|string|max:100',
        ]);

        $vendor->update([
            'type' => $validated['type'] ?? 'B2C',
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'country_code' => isset($validated['country_code']) ? strtoupper($validated['country_code']) : null,
            'currency_code' => isset($validated['currency_code']) ? strtoupper($validated['currency_code']) : $vendor->currency_code,
            'payment_terms' => $validated['payment_terms'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'vendor' => $this->vendorPayload($vendor->fresh()),
        ]);
    }

    public function deleteVendor(string $uid, Request $request): JsonResponse
    {
        $user = $request->user();
        $vendor = Vendor::where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->where('uid', $uid)
            ->firstOrFail();

        if ($this->hasVendorFinancialLinks($vendor)) {
            return response()->json(['error' => 'This vendor is attached to financial activity and cannot be deleted.'], 422);
        }

        $vendor->delete();

        return response()->json(['success' => true, 'message' => 'Vendor deleted successfully.']);
    }

    private function resolveCompany(Request $request, int $tenantId, int $defaultCompanyId): Company
    {
        return Company::where('tenant_id', $tenantId)->findOrFail($defaultCompanyId);
    }

    private function customerRules(): array
    {
        return [
            'name' => 'required|string|max:200',
            'type' => 'nullable|in:B2B,B2C',
            'email' => 'nullable|email|max:200',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:1000',
            'city' => 'nullable|string|max:100',
            'country_code' => 'nullable|string|size:2',
            'currency_code' => 'nullable|string|size:3|exists:currencies,code',
        ];
    }

    private function customerPayload(Customer $customer, ?float $outstandingBalance = null): array
    {
        $payload = [
            ...$customer->only(['id', 'uid', 'type', 'name', 'email', 'phone', 'address', 'city', 'country_code', 'currency_code']),
            'can_manage' => !$this->hasCustomerFinancialLinks($customer),
        ];

        if ($outstandingBalance !== null) {
            $payload['outstanding_balance'] = round($outstandingBalance, 4);
        }

        return $payload;
    }

    private function vendorPayload(Vendor $vendor, ?float $outstandingBalance = null): array
    {
        $payload = [
            ...$vendor->only(['id', 'uid', 'type', 'name', 'email', 'phone', 'address', 'city', 'country_code', 'currency_code', 'payment_terms']),
            'can_manage' => !$this->hasVendorFinancialLinks($vendor),
        ];

        if ($outstandingBalance !== null) {
            $payload['outstanding_balance'] = round($outstandingBalance, 4);
        }

        return $payload;
    }

    private function canSeeOutstanding(User $user): bool
    {
        return !$user->hasAnyRole(['sales']);
    }

    private function customerOutstandingBalances($customers, int $tenantId, int $companyId): array
    {
        $statementService = app(StatementService::class);

        return $customers
            ->mapWithKeys(function (Customer $customer) use ($statementService, $tenantId, $companyId): array {
                $statement = $statementService->customerStatement($tenantId, $companyId, $customer->id);

                return [$customer->id => (float) ($statement['summary']['total_outstanding'] ?? 0)];
            })
            ->all();
    }

    private function vendorOutstandingBalances($vendors, int $tenantId, int $companyId): array
    {
        $statementService = app(StatementService::class);

        return $vendors
            ->mapWithKeys(function (Vendor $vendor) use ($statementService, $tenantId, $companyId): array {
                $statement = $statementService->vendorStatement($tenantId, $companyId, $vendor->id);

                return [$vendor->id => (float) ($statement['summary']['outstanding_balance'] ?? 0)];
            })
            ->all();
    }

    private function hasCustomerFinancialLinks(Customer $customer): bool
    {
        return $customer->orders()->exists()
            || $customer->invoices()->exists()
            || $customer->receipts()->exists()
            || $customer->payments()->exists();
    }

    private function hasVendorFinancialLinks(Vendor $vendor): bool
    {
        return $vendor->orders()->exists()
            || $vendor->orderCosts()->exists()
            || $vendor->receipts()->exists()
            || $vendor->payments()->exists();
    }
}
