<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['code' => 'DEMO001'],
            ['uid' => (string) Str::ulid(), 'name' => 'Demo Tenant', 'is_active' => true]
        );

        $company = Company::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'display_name' => 'Demo Company'],
            [
                'uid' => (string) Str::ulid(),
                'legal_name' => 'Demo Company Ltd',
                'email' => 'info@demo.local',
                'base_currency_code' => 'PKR',
                'default_timezone' => 'UTC',
                'monthly_invoice_limit' => null,
                'order_limit' => null,
                'user_limit' => 2,
                'is_paid' => false,
                'sales_can_edit_cost' => false,
                'is_active' => true,
            ]
        );

        $tenantRoles = [];

        foreach (Role::TENANT_DEFAULT_ROLES as $roleDefaults) {
            $tenantRoles[$roleDefaults['code']] = Role::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $roleDefaults['code']],
                ['uid' => (string) Str::ulid(), 'name' => $roleDefaults['name'], 'is_system' => false]
            );
        }

        $ownerRole = $tenantRoles[Role::SIGNUP_DEFAULT_ROLE];

        User::query()->firstOrCreate([
            'email' => 'admin@demoagency.com',
        ], [
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'role_id' => $ownerRole->id,
            'name' => 'Owner',
            'password' => 'password123',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $internalTenant = Tenant::query()->firstOrCreate(
            ['code' => 'INYICE'],
            ['uid' => (string) Str::ulid(), 'name' => 'InYice Operations', 'is_active' => true]
        );

        $internalCompany = Company::query()->firstOrCreate(
            ['tenant_id' => $internalTenant->id, 'display_name' => 'InYice Operations'],
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
                'sales_can_edit_cost' => true,
                'is_active' => true,
            ]
        );

        $systemRoles = [];

        foreach (Role::SYSTEM_ROLES as $roleDefaults) {
            $systemRoles[$roleDefaults['code']] = Role::query()->firstOrCreate(
                ['tenant_id' => null, 'code' => $roleDefaults['code']],
                ['uid' => (string) Str::ulid(), 'name' => $roleDefaults['name'], 'is_system' => true]
            );
        }

        User::query()->firstOrCreate([
            'email' => 'superadmin@inyice.local',
        ], [
            'uid' => (string) Str::ulid(),
            'tenant_id' => $internalTenant->id,
            'company_id' => $internalCompany->id,
            'role_id' => $systemRoles['super-admin']->id,
            'name' => 'InYice Super Admin',
            'password' => 'password123',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }
}
