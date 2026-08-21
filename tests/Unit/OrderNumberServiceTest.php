<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OrderNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_highest_existing_daily_suffix_instead_of_order_count(): void
    {
        Carbon::setTestNow('2026-08-21 13:32:48');

        [$tenant, $company, $customer, $user] = $this->tenantCompanyCustomerAndUser();

        $this->createOrder($tenant, $company, $customer, $user, 'ORD-20260821-00002');

        $service = new OrderNumberService();

        $this->assertSame('ORD-20260821-00003', $service->generateOrderNumber($company->id, $tenant->id));

        $softDeletedOrder = $this->createOrder($tenant, $company, $customer, $user, 'ORD-20260821-00003');
        $softDeletedOrder->delete();

        $this->assertSame('ORD-20260821-00004', $service->generateOrderNumber($company->id, $tenant->id));
    }

    private function tenantCompanyCustomerAndUser(): array
    {
        $tenant = Tenant::create([
            'uid' => (string) Str::ulid(),
            'code' => 'TEST',
            'name' => 'Test Tenant',
            'is_active' => true,
        ]);

        $company = Company::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'legal_name' => 'Test Company LLC',
            'display_name' => 'Test Company',
            'base_currency_code' => 'PKR',
            'default_timezone' => 'UTC',
            'is_active' => true,
        ]);

        $role = Role::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'code' => 'admin',
            'name' => 'Admin',
        ]);

        $user = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Test Customer',
            'currency_code' => 'PKR',
            'is_active' => true,
        ]);

        return [$tenant, $company, $customer, $user];
    }

    private function createOrder(Tenant $tenant, Company $company, Customer $customer, User $user, string $orderNumber): Order
    {
        return Order::create([
            'tenant_id' => $tenant->id,
            'uid' => (string) Str::ulid(),
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
            'order_number' => $orderNumber,
            'issue_date' => Carbon::now()->toDateString(),
            'status' => 'order',
            'currency_code' => 'PKR',
            'total_amount' => 0,
        ]);
    }
}
