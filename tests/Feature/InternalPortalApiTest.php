<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InternalPortalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_executive_can_view_companies_and_update_limits_without_creating_staff(): void
    {
        $ctx = $this->seedInternalContext('support-executive');
        $company = $this->seedCustomerCompany();
        Sanctum::actingAs($ctx['user']);

        $this->getJson('/api/v1/internal/companies')
            ->assertOk()
            ->assertJsonFragment(['display_name' => 'Customer Company']);

        $this->patchJson('/api/v1/internal/companies/' . $company->uid . '/limits', [
            'monthly_invoice_limit' => 75,
            'order_limit' => 200,
            'user_limit' => 8,
        ])->assertStatus(422);

        $this->patchJson('/api/v1/internal/companies/' . $company->uid . '/limits', [
            'monthly_invoice_limit' => 75,
            'order_limit' => 200,
            'user_limit' => 2,
        ])->assertOk()
            ->assertJsonPath('company.monthly_invoice_limit', null)
            ->assertJsonPath('company.order_limit', null)
            ->assertJsonPath('company.user_limit', 2);

        $this->postJson('/api/v1/internal/users', [
            'name' => 'Blocked Staff',
            'email' => 'blocked-staff@inyice.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'support-executive',
        ])->assertStatus(403);
    }

    public function test_internal_portal_can_toggle_sales_cost_access(): void
    {
        $ctx = $this->seedInternalContext('support-executive');
        $company = $this->seedCustomerCompany();
        Sanctum::actingAs($ctx['user']);

        $this->patchJson('/api/v1/internal/companies/' . $company->uid . '/limits', [
            'user_limit' => 2,
            'sales_can_edit_cost' => true,
        ])->assertOk()
            ->assertJsonPath('company.sales_can_edit_cost', true);

        $this->assertTrue($company->fresh()->sales_can_edit_cost);
    }

    public function test_internal_portal_invoice_counts_exclude_cancelled_invoices(): void
    {
        $this->travelTo('2026-08-15 10:00:00');

        $ctx = $this->seedInternalContext('support-executive');
        $records = $this->seedCustomerRecords();
        $company = $records['company'];
        $baseInvoice = $records['invoice'];

        Invoice::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'order_id' => $baseInvoice->order_id,
            'customer_id' => $baseInvoice->customer_id,
            'invoice_number' => 'INV-SUPPORT-CANCEL',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'PKR',
            'subtotal' => 250,
            'tax_amount' => 0,
            'total_amount' => 250,
            'outstanding_amount' => 0,
            'status' => 'cancel',
            'fx_rate_to_base' => 1,
        ]);

        Invoice::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'order_id' => $baseInvoice->order_id,
            'customer_id' => $baseInvoice->customer_id,
            'invoice_number' => 'INV-SUPPORT-OLD',
            'invoice_date' => now()->subMonthNoOverflow()->toDateString(),
            'due_date' => now()->subMonthNoOverflow()->addDays(30)->toDateString(),
            'currency_code' => 'PKR',
            'subtotal' => 300,
            'tax_amount' => 0,
            'total_amount' => 300,
            'outstanding_amount' => 300,
            'status' => 'issued',
            'fx_rate_to_base' => 1,
        ]);

        Sanctum::actingAs($ctx['user']);

        $this->getJson('/api/v1/internal/companies')
            ->assertOk()
            ->assertJsonPath('companies.0.counts.invoices', 2)
            ->assertJsonPath('companies.0.counts.monthly_invoices', 1);

        $this->getJson('/api/v1/internal/companies/'.$company->uid)
            ->assertOk()
            ->assertJsonPath('company.counts.invoices', 2)
            ->assertJsonPath('company.counts.monthly_invoices', 1)
            ->assertJsonMissing(['invoice_number' => 'INV-SUPPORT-CANCEL']);
    }

    public function test_super_admin_can_create_internal_staff(): void
    {
        $ctx = $this->seedInternalContext('super-admin');
        Sanctum::actingAs($ctx['user']);

        $response = $this->postJson('/api/v1/internal/users', [
            'name' => 'Support Agent',
            'email' => 'support-agent@inyice.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'support-executive',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'support-agent@inyice.local')
            ->assertJsonPath('user.role', 'support-executive');
    }

    public function test_system_user_login_identifies_internal_portal_access(): void
    {
        $ctx = $this->seedInternalContext('inyice-admin');

        $this->postJson('/api/v1/auth/login', [
            'email' => $ctx['user']->email,
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('user.role', 'inyice-admin')
            ->assertJsonPath('user.is_system_user', true);
    }

    public function test_internal_user_can_reset_own_password(): void
    {
        $ctx = $this->seedInternalContext('support-executive');
        Sanctum::actingAs($ctx['user']);

        $this->postJson('/api/v1/internal/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertStatus(422);

        $this->postJson('/api/v1/internal/profile/password', [
            'current_password' => 'password123',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password123', $ctx['user']->fresh()->password));
    }

    public function test_internal_user_can_view_read_only_order_and_invoice_details(): void
    {
        $ctx = $this->seedInternalContext('support-executive');
        $records = $this->seedCustomerRecords();
        Sanctum::actingAs($ctx['user']);

        $this->getJson('/api/v1/internal/orders/' . $records['order']->uid)
            ->assertOk()
            ->assertJsonPath('uid', $records['order']->uid)
            ->assertJsonPath('company.display_name', 'Customer Company')
            ->assertJsonPath('items.0.description', 'Support Ticket');

        $this->getJson('/api/v1/internal/invoices/' . $records['invoice']->uid)
            ->assertOk()
            ->assertJsonPath('uid', $records['invoice']->uid)
            ->assertJsonPath('company.display_name', 'Customer Company')
            ->assertJsonPath('lines.0.description', 'Support Ticket');
    }

    public function test_company_admin_cannot_access_internal_portal(): void
    {
        $company = $this->seedCustomerCompany();
        $role = Role::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $company->tenant_id,
            'code' => 'admin',
            'name' => 'Admin',
            'is_system' => false,
        ]);
        $user = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'role_id' => $role->id,
            'name' => 'Company Admin',
            'email' => 'company-admin@test.local',
            'password' => 'password123',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/internal/companies')->assertStatus(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function seedInternalContext(string $roleCode): array
    {
        $tenant = Tenant::create([
            'uid' => (string) Str::ulid(),
            'code' => 'INYICE',
            'name' => 'InYice Operations',
            'is_active' => true,
        ]);

        $company = Company::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'legal_name' => 'InYice Operations',
            'display_name' => 'InYice Operations',
            'email' => 'support@inyice.local',
            'base_currency_code' => 'PKR',
            'default_timezone' => 'UTC',
            'monthly_invoice_limit' => null,
            'order_limit' => null,
            'user_limit' => 2,
            'is_active' => true,
        ]);

        $roles = [];

        foreach (Role::SYSTEM_ROLES as $roleDefaults) {
            $roles[$roleDefaults['code']] = Role::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => null,
                'code' => $roleDefaults['code'],
                'name' => $roleDefaults['name'],
                'is_system' => true,
            ]);
        }

        $user = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'role_id' => $roles[$roleCode]->id,
            'name' => 'Internal User',
            'email' => 'internal-' . fake()->unique()->safeEmail(),
            'password' => 'password123',
            'is_active' => true,
        ]);

        return [
            'tenant' => $tenant,
            'company' => $company,
            'roles' => $roles,
            'user' => $user,
        ];
    }

    private function seedCustomerCompany(): Company
    {
        $tenant = Tenant::create([
            'uid' => (string) Str::ulid(),
            'code' => 'cust' . fake()->unique()->numerify('###'),
            'name' => 'Customer Tenant',
            'is_active' => true,
        ]);

        return Company::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'legal_name' => 'Customer Company Legal',
            'display_name' => 'Customer Company',
            'email' => 'customer-company@test.local',
            'base_currency_code' => 'PKR',
            'default_timezone' => 'UTC',
            'monthly_invoice_limit' => null,
            'order_limit' => null,
            'user_limit' => 2,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function seedCustomerRecords(): array
    {
        $company = $this->seedCustomerCompany();

        $customer = Customer::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'type' => 'B2C',
            'name' => 'Support Customer',
            'currency_code' => 'PKR',
            'is_active' => true,
        ]);

        $vendor = Vendor::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'type' => 'B2B',
            'name' => 'Support Vendor',
            'currency_code' => 'PKR',
            'is_active' => true,
        ]);

        $role = Role::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $company->tenant_id,
            'code' => 'owner',
            'name' => 'Owner',
            'is_system' => false,
        ]);

        $creator = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'role_id' => $role->id,
            'name' => 'Company Owner',
            'email' => 'records-owner-' . fake()->unique()->safeEmail(),
            'password' => 'password123',
            'is_active' => true,
        ]);

        $order = Order::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
            'order_number' => 'ORD-SUPPORT-001',
            'booking_reference' => 'SUP123',
            'status' => 'invoice',
            'currency_code' => 'PKR',
            'total_amount' => 500,
            'meta' => [
                'voucher_no' => 'VCH-SUPPORT-001',
                'passengers' => [['name' => 'Support Passenger']],
            ],
        ]);

        OrderItem::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $company->tenant_id,
            'order_id' => $order->id,
            'description' => 'Support Ticket',
            'quantity' => 1,
            'unit_price' => 500,
            'total_price' => 500,
        ]);

        $invoice = Invoice::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-SUPPORT-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'PKR',
            'subtotal' => 500,
            'tax_amount' => 0,
            'total_amount' => 500,
            'outstanding_amount' => 500,
            'status' => 'issued',
            'fx_rate_to_base' => 1,
        ]);

        InvoiceLine::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $company->tenant_id,
            'invoice_id' => $invoice->id,
            'description' => 'Support Ticket',
            'quantity' => 1,
            'unit_price' => 500,
            'total_price' => 500,
        ]);

        return [
            'company' => $company,
            'order' => $order,
            'invoice' => $invoice,
        ];
    }
}
