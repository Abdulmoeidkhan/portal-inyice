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
            ['code' => 'demo001'],
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
                'is_active' => true,
            ]
        );

        $adminRole = Role::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'admin'],
            ['uid' => (string) Str::ulid(), 'name' => 'Admin', 'is_system' => false]
        );

        User::query()->firstOrCreate([
            'email' => 'admin@demoagency.com',
        ], [
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'role_id' => $adminRole->id,
            'name' => 'Admin User',
            'password' => 'password123',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }
}
