<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InternalPortalController extends Controller
{
    public function companies(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $query = Company::withoutGlobalScopes()
            ->with('tenant:id,name,code')
            ->withCount(['users', 'customers', 'vendors', 'orders', 'invoices'])
            ->whereHas('tenant', fn ($tenant) => $tenant->where('code', '!=', 'INYICE'))
            ->orderBy('display_name');

        if ($search !== '') {
            $query->where(function ($company) use ($search) {
                $company->where('display_name', 'like', "%{$search}%")
                    ->orWhere('legal_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('tenant', fn ($tenant) => $tenant
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%"));
            });
        }

        $companies = $query->limit(100)->get();

        return response()->json([
            'companies' => $companies->map(fn (Company $company) => $this->serializeCompanySummary($company))->values(),
        ]);
    }

    public function company(string $uid): JsonResponse
    {
        $company = Company::withoutGlobalScopes()
            ->with([
                'tenant:id,name,code,is_active',
                'users.role:id,code,name,is_system',
            ])
            ->whereHas('tenant', fn ($tenant) => $tenant->where('code', '!=', 'INYICE'))
            ->where('uid', $uid)
            ->firstOrFail();

        return response()->json([
            'company' => $this->serializeCompanyDetail($company),
            'records' => $this->companyRecords($company),
        ]);
    }

    public function updateCompanyLimits(Request $request, string $uid): JsonResponse
    {
        $company = Company::withoutGlobalScopes()
            ->whereHas('tenant', fn ($tenant) => $tenant->where('code', '!=', 'INYICE'))
            ->where('uid', $uid)
            ->firstOrFail();

        $validated = $request->validate([
            'monthly_invoice_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'order_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'user_limit' => ['sometimes', 'integer', 'min:1', 'max:2'],
            'is_paid' => ['sometimes', 'boolean'],
        ]);

        $validated['monthly_invoice_limit'] = null;
        $validated['order_limit'] = null;
        $validated['user_limit'] = 2;

        $company->update($validated);

        return response()->json([
            'success' => true,
            'company' => $this->serializeCompanyDetail($company->fresh(['tenant', 'users.role'])),
        ]);
    }

    public function updateCompanyStatus(Request $request, string $uid): JsonResponse
    {
        $company = Company::withoutGlobalScopes()
            ->whereHas('tenant', fn ($tenant) => $tenant->where('code', '!=', 'INYICE'))
            ->where('uid', $uid)
            ->firstOrFail();

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $company->update([
            'is_active' => $validated['is_active'],
        ]);

        if (!$company->is_active) {
            User::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->each(fn (User $user) => $user->tokens()->delete());
        }

        return response()->json([
            'success' => true,
            'message' => $company->is_active ? 'Company unblocked successfully.' : 'Company blocked successfully.',
            'company' => $this->serializeCompanyDetail($company->fresh(['tenant', 'users.role'])),
        ]);
    }

    public function internalUsers(): JsonResponse
    {
        $users = User::withoutGlobalScopes()
            ->with('role:id,code,name,is_system')
            ->whereHas('role', fn ($role) => $role->where('is_system', true))
            ->orderBy('name')
            ->get(['id', 'uid', 'role_id', 'name', 'email', 'is_active', 'created_at']);

        return response()->json([
            'users' => $users->map(fn (User $user) => $this->serializeInternalUser($user))->values(),
            'roles' => collect(Role::SYSTEM_ROLES)
                ->whereIn('code', ['inyice-admin', 'support-executive'])
                ->values(),
        ]);
    }

    public function createInternalUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:200', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(['inyice-admin', 'support-executive'])],
        ]);

        $role = $this->systemRole($validated['role']);
        $tenant = $this->internalTenant();
        $company = $this->internalCompany($tenant);

        $user = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'is_active' => true,
        ]);

        $user->load('role:id,code,name,is_system');

        return response()->json([
            'success' => true,
            'user' => $this->serializeInternalUser($user),
        ], 201);
    }

    public function updateUserStatus(Request $request, string $uid): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $target = User::withoutGlobalScopes()
            ->with('role:id,code,name,is_system')
            ->where('uid', $uid)
            ->firstOrFail();

        if ($target->id === $request->user()->id && !$validated['is_active']) {
            return response()->json([
                'error' => 'You cannot block your own account.',
            ], 422);
        }

        $target->forceFill([
            'is_active' => $validated['is_active'],
        ])->save();

        if (!$target->is_active) {
            $target->tokens()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => $target->is_active ? 'User unblocked successfully.' : 'User blocked successfully.',
            'user' => $this->serializeUserForManagement($target->fresh('role:id,code,name,is_system')),
        ]);
    }

    public function resetUserPassword(Request $request, string $uid): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $target = User::withoutGlobalScopes()
            ->with('role:id,code,name,is_system')
            ->where('uid', $uid)
            ->firstOrFail();

        $target->forceFill([
            'password' => $validated['password'],
        ])->save();

        $target->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully.',
            'user' => $this->serializeUserForManagement($target->fresh('role:id,code,name,is_system')),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password ?? '')) {
            return response()->json([
                'error' => 'Current password is incorrect.',
            ], 422);
        }

        $user->forceFill([
            'password' => $validated['password'],
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully.',
        ]);
    }

    public function order(string $uid): JsonResponse
    {
        $order = Order::withoutGlobalScopes()
            ->with(['company.tenant:id,name,code', 'customer', 'vendor', 'items', 'invoice:id,order_id,uid,invoice_number,status,outstanding_amount'])
            ->whereHas('company.tenant', fn ($tenant) => $tenant->where('code', '!=', 'INYICE'))
            ->where('uid', $uid)
            ->firstOrFail();

        return response()->json($order);
    }

    public function invoice(string $uid): JsonResponse
    {
        $invoice = Invoice::withoutGlobalScopes()
            ->with(['company.tenant:id,name,code', 'customer', 'order', 'lines', 'settlements'])
            ->whereHas('company.tenant', fn ($tenant) => $tenant->where('code', '!=', 'INYICE'))
            ->where('uid', $uid)
            ->firstOrFail();

        return response()->json($invoice);
    }

    private function serializeCompanySummary(Company $company): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $monthlyInvoices = Invoice::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereBetween('invoice_date', [$monthStart, $monthEnd])
            ->count();

        return [
            'id' => $company->id,
            'uid' => $company->uid,
            'tenant' => $company->tenant?->only(['id', 'name', 'code']),
            'legal_name' => $company->legal_name,
            'display_name' => $company->display_name,
            'email' => $company->email,
            'phone' => $company->phone,
            'base_currency_code' => $company->base_currency_code,
            'monthly_invoice_limit' => $company->monthly_invoice_limit,
            'order_limit' => $company->order_limit,
            'user_limit' => (int) ($company->user_limit ?: 2),
            'is_paid' => (bool) $company->is_paid,
            'is_active' => (bool) $company->is_active,
            'counts' => [
                'users' => (int) ($company->users_count ?? 0),
                'customers' => (int) ($company->customers_count ?? 0),
                'vendors' => (int) ($company->vendors_count ?? 0),
                'orders' => (int) ($company->orders_count ?? 0),
                'invoices' => (int) ($company->invoices_count ?? 0),
                'monthly_invoices' => $monthlyInvoices,
            ],
        ];
    }

    private function serializeCompanyDetail(Company $company): array
    {
        return [
            ...$this->serializeCompanySummary($company->loadCount(['users', 'customers', 'vendors', 'orders', 'invoices'])),
            'address' => $company->address,
            'country_code' => $company->country_code,
            'default_timezone' => $company->default_timezone,
            'created_at' => optional($company->created_at)->toISOString(),
            'users' => $company->users
                ->map(fn (User $user) => $this->serializeUserForManagement($user))
                ->values(),
        ];
    }

    private function companyRecords(Company $company): array
    {
        return [
            'orders' => Order::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->latest()
                ->limit(10)
                ->get(['uid', 'order_number', 'booking_reference', 'status', 'currency_code', 'total_amount', 'created_at']),
            'invoices' => Invoice::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->orderByDesc('invoice_date')
                ->limit(10)
                ->get(['uid', 'invoice_number', 'invoice_date', 'status', 'currency_code', 'total_amount', 'outstanding_amount']),
            'payments' => Payment::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->orderByDesc('payment_date')
                ->limit(10)
                ->get(['uid', 'payment_number', 'payment_date', 'amount', 'currency_code', 'payment_method']),
            'receipts' => Receipt::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->orderByDesc('receipt_date')
                ->limit(10)
                ->get(['uid', 'receipt_number', 'receipt_date', 'amount', 'currency_code', 'payment_method']),
        ];
    }

    private function serializeInternalUser(User $user): array
    {
        return $this->serializeUserForManagement($user);
    }

    private function serializeUserForManagement(User $user): array
    {
        return [
            'id' => $user->id,
            'uid' => $user->uid,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->code,
            'role_code' => $user->role?->code,
            'role_name' => $user->role?->name,
            'is_system_user' => (bool) $user->role?->is_system,
            'is_active' => (bool) $user->is_active,
            'created_at' => optional($user->created_at)->toISOString(),
        ];
    }

    private function systemRole(string $code): Role
    {
        $roleDefaults = collect(Role::SYSTEM_ROLES)->firstWhere('code', $code);

        return Role::query()->firstOrCreate(
            ['tenant_id' => null, 'code' => $code],
            ['uid' => (string) Str::ulid(), 'name' => $roleDefaults['name'], 'is_system' => true]
        );
    }

    private function internalTenant(): Tenant
    {
        return Tenant::query()->firstOrCreate(
            ['code' => 'INYICE'],
            ['uid' => (string) Str::ulid(), 'name' => 'InYice Operations', 'is_active' => true]
        );
    }

    private function internalCompany(Tenant $tenant): Company
    {
        return Company::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'display_name' => 'InYice Operations'],
            [
                'uid' => (string) Str::ulid(),
                'legal_name' => 'InYice Operations',
                'email' => 'support@inyice.local',
                'base_currency_code' => 'PKR',
                'default_timezone' => 'UTC',
                'monthly_invoice_limit' => null,
                'order_limit' => null,
                'user_limit' => 2,
                'is_paid' => true,
                'is_active' => true,
            ]
        );
    }
}
