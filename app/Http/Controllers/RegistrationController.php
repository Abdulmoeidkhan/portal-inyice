<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\CashAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegistrationController extends Controller
{
    /**
     * Register new agency (tenant + company + admin user)
     */
    public function register(Request $request)
    {
        if ($request->has('agency_code')) {
            $request->merge([
                'agency_code' => Str::upper(trim((string) $request->input('agency_code'))),
            ]);
        }

        $validated = $request->validate([
            // Tenant info
            'agency_code' => 'required|string|max:50|regex:/^[A-Z0-9]+$/|unique:tenants,code',
            'agency_name' => 'required|string|max:200',
            
            // Company info
            'company_legal_name' => 'required|string|max:200',
            'company_email' => 'required|email|unique:companies,email',
            'company_phone' => 'required|string|max:50',
            'billing_address' => 'required|string|max:1000',
            'base_currency_code' => 'required|exists:currencies,code',
            'timezone' => 'required|timezone',
            
            // Admin user
            'admin_name' => 'required|string|max:200',
            'admin_email' => 'required|email|unique:users,email',
            'admin_password' => 'required|string|min:8|confirmed',
        ]);

        try {
            return DB::transaction(function () use ($validated) {
                // Create tenant
                $tenant = Tenant::create([
                    'uid' => (string) Str::ulid(),
                    'code' => $validated['agency_code'],
                    'name' => $validated['agency_name'],
                    'is_active' => true,
                ]);

                $tenantRoles = [];

                foreach (Role::TENANT_DEFAULT_ROLES as $roleDefaults) {
                    $tenantRoles[$roleDefaults['code']] = Role::firstOrCreate(
                        ['tenant_id' => $tenant->id, 'code' => $roleDefaults['code']],
                        [
                            'uid' => (string) Str::ulid(),
                            'name' => $roleDefaults['name'],
                            'is_system' => false,
                        ]
                    );
                }

                $ownerRole = $tenantRoles[Role::SIGNUP_DEFAULT_ROLE];

                // Create company
                $company = Company::create([
                    'uid' => (string) Str::ulid(),
                    'tenant_id' => $tenant->id,
                    'legal_name' => $validated['company_legal_name'],
                    'display_name' => $validated['agency_name'],
                    'email' => $validated['company_email'],
                    'phone' => $validated['company_phone'],
                    'address' => $validated['billing_address'],
                    'base_currency_code' => $validated['base_currency_code'],
                    'default_timezone' => $validated['timezone'],
                    'monthly_invoice_limit' => null,
                    'order_limit' => null,
                    'user_limit' => 2,
                    'is_paid' => false,
                    'sales_can_edit_cost' => false,
                    'is_active' => true,
                ]);

                // Create owner user
                $user = User::create([
                    'uid' => (string) Str::ulid(),
                    'tenant_id' => $tenant->id,
                    'company_id' => $company->id,
                    'role_id' => $ownerRole->id,
                    'name' => $validated['admin_name'],
                    'email' => $validated['admin_email'],
                    'password' => $validated['admin_password'],
                ]);

                // Create default cash account
                CashAccount::create([
                    'uid' => (string) Str::ulid(),
                    'tenant_id' => $tenant->id,
                    'company_id' => $company->id,
                    'account_code' => 'CA-' . str_pad((string) $tenant->id, 4, '0', STR_PAD_LEFT),
                    'account_name' => 'Main Cash Box',
                    'currency_code' => $validated['base_currency_code'],
                    'opening_balance' => 0,
                    'current_balance' => 0,
                    'is_active' => true,
                ]);

                // Create API token
                $token = $user->createToken('web')->plainTextToken;

                return response()->json([
                    'success' => true,
                    'message' => 'Agency registered successfully',
                    'token' => $token,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $ownerRole->code,
                        'company_is_paid' => false,
                        'company_sales_can_edit_cost' => false,
                        'company_name' => $company->display_name,
                        'tenant_name' => $tenant->name,
                    ],
                ], 201);
            });
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'error' => 'Registration failed',
            ], 422);
        }
    }

    /**
     * Get available currencies
     */
    public function getCurrencies()
    {
        $currencies = DB::table('currencies')
            ->select('code', 'name')
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        return response()->json($currencies);
    }

    /**
     * Get timezones
     */
    public function getTimezones()
    {
        $timezones = \DateTimeZone::listIdentifiers();
        return response()->json($timezones);
    }

    /**
     * Check if agency code is available
     */
    public function checkAgencyCode(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50',
        ]);

        $code = Str::upper(trim($validated['code']));
        $exists = Tenant::where('code', $code)->exists();
        
        return response()->json([
            'available' => !$exists,
            'code' => $code,
        ]);
    }
}
