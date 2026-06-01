<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_endpoints_work_for_authenticated_user(): void
    {
        $ctx = $this->seedTenantContext();

        $create = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ]);

        $create->assertCreated();
        $create->assertJsonPath('success', true);

        $invoiceUid = $create->json('invoice.uid');

        $list = $this->getJson('/api/v1/invoices');
        $list->assertOk();
        $list->assertJsonPath('total', 1);

        $show = $this->getJson('/api/v1/invoices/' . $invoiceUid);
        $show->assertOk();
        $show->assertJsonPath('uid', $invoiceUid);

        $markSent = $this->patchJson('/api/v1/invoices/' . $invoiceUid . '/mark-sent');
        $markSent->assertOk();
        $markSent->assertJsonPath('invoice.status', 'sent');

        $aging = $this->getJson('/api/v1/invoices/' . $invoiceUid . '/aging-status');
        $aging->assertOk();
        $aging->assertJsonPath('invoice_uid', $invoiceUid);

        $void = $this->patchJson('/api/v1/invoices/' . $invoiceUid . '/void');
        $void->assertOk();
        $void->assertJsonPath('invoice.status', 'void');
    }

    public function test_payment_and_account_endpoints_work(): void
    {
        $ctx = $this->seedTenantContext();

        $invoiceResponse = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ]);
        $invoiceResponse->assertCreated();

        $invoiceUid = $invoiceResponse->json('invoice.uid');

        $bankCreate = $this->postJson('/api/v1/accounts/bank', [
            'bank_name' => 'Demo Bank',
            'account_number' => 'AC-' . fake()->unique()->numerify('########'),
            'account_holder' => 'API Test Holder',
            'currency_code' => 'PKR',
            'opening_balance' => 1000,
        ]);

        $bankCreate->assertCreated();
        $bankId = $bankCreate->json('account.id');

        $payment = $this->postJson('/api/v1/payments/record', [
            'invoice_uid' => $invoiceUid,
            'amount' => 400,
            'payment_method' => 'bank_transfer',
            'account_id' => $bankId,
            'reference_number' => 'PAY-001',
        ]);

        $payment->assertCreated();
        $payment->assertJsonPath('success', true);
        $payment->assertJsonPath('invoice.status', 'partial_paid');

        $advance = $this->postJson('/api/v1/payments/advance', [
            'invoice_uid' => $invoiceUid,
            'amount' => 200,
            'payment_method' => 'cash',
        ]);
        $advance->assertCreated();

        $applyAdvance = $this->postJson('/api/v1/payments/apply-advance', [
            'invoice_uid' => $invoiceUid,
            'advance_amount' => 100,
        ]);
        $applyAdvance->assertCreated();

        $settlements = $this->getJson('/api/v1/payments/invoices/' . $invoiceUid . '/settlements');
        $settlements->assertOk();
        $settlements->assertJsonPath('invoice_uid', $invoiceUid);

        $bankList = $this->getJson('/api/v1/accounts/bank');
        $bankList->assertOk();

        $balance = $this->getJson('/api/v1/accounts/bank/' . $bankId . '/balance');
        $balance->assertOk();
        $balance->assertJsonPath('account_type', 'bank');
    }

    public function test_reports_and_statements_endpoints_work(): void
    {
        $ctx = $this->seedTenantContext();

        $invoiceResponse = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ]);
        $invoiceResponse->assertCreated();

        $invoiceUid = $invoiceResponse->json('invoice.uid');
        $this->patchJson('/api/v1/invoices/' . $invoiceUid . '/mark-sent')->assertOk();

        $this->postJson('/api/v1/payments/record', [
            'invoice_uid' => $invoiceUid,
            'amount' => 250,
            'payment_method' => 'cash',
        ])->assertCreated();

        $aging = $this->getJson('/api/v1/reports/aging');
        $aging->assertOk();
        $aging->assertJsonStructure([
            'report_date',
            'company_id',
            'buckets',
            'summary',
        ]);

        $revenue = $this->getJson('/api/v1/reports/revenue?from_date=2020-01-01&to_date=2030-12-31&group_by=month');
        $revenue->assertOk();
        $revenue->assertJsonPath('group_by', 'month');

        $customerSummary = $this->getJson('/api/v1/reports/customer-summary');
        $customerSummary->assertOk();
        $customerSummary->assertJsonStructure([
            'report_date',
            'customers',
            'summary',
        ]);

        $customerStatement = $this->getJson('/api/v1/statements/customer/' . $ctx['customer']->id);
        $customerStatement->assertOk();
        $customerStatement->assertJsonStructure([
            'customer',
            'statement_date',
            'summary',
        ]);

        $vendorStatement = $this->getJson('/api/v1/statements/vendor/' . $ctx['vendor']->id);
        $vendorStatement->assertOk();
        $vendorStatement->assertJsonStructure([
            'vendor',
            'statement_date',
            'summary',
        ]);
    }

    private function seedTenantContext(): array
    {
        $tenant = Tenant::create([
            'uid' => (string) Str::ulid(),
            'code' => 'api' . fake()->unique()->numerify('###'),
            'name' => 'API Test Tenant',
            'is_active' => true,
        ]);

        $company = Company::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'legal_name' => 'API Test Company Legal',
            'display_name' => 'API Test Company',
            'email' => 'company-' . fake()->unique()->safeEmail(),
            'phone' => '+92-300-1234567',
            'address' => 'Karachi, Pakistan',
            'base_currency_code' => 'PKR',
            'default_timezone' => 'Asia/Karachi',
            'is_active' => true,
        ]);

        $role = Role::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'code' => 'admin',
            'name' => 'Admin',
            'is_system' => false,
        ]);

        $user = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
            'name' => 'API Tester',
            'email' => 'user-' . fake()->unique()->safeEmail(),
            'password' => 'password123',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => 'B2C',
            'name' => 'Test Customer',
            'email' => 'customer-' . fake()->unique()->safeEmail(),
            'phone' => '+92-300-1111111',
            'address' => 'Lahore, Pakistan',
            'city' => 'Lahore',
            'country_code' => 'PK',
            'postal_code' => '54000',
            'currency_code' => 'PKR',
            'is_active' => true,
        ]);

        $vendor = Vendor::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => 'B2B',
            'name' => 'Test Vendor',
            'email' => 'vendor-' . fake()->unique()->safeEmail(),
            'phone' => '+92-300-2222222',
            'address' => 'Islamabad, Pakistan',
            'city' => 'Islamabad',
            'country_code' => 'PK',
            'postal_code' => '44000',
            'currency_code' => 'PKR',
            'payment_terms' => '30 days',
            'is_active' => true,
        ]);

        $order = Order::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
            'order_number' => 'ORD-' . fake()->unique()->numerify('######'),
            'booking_reference' => 'PNR' . fake()->numerify('#####'),
            'status' => 'order',
            'currency_code' => 'PKR',
            'total_amount' => 1000,
            'notes' => 'API order',
        ]);

        OrderItem::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'description' => 'Flight Ticket',
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
        ]);

        Sanctum::actingAs($user);

        return [
            'tenant' => $tenant,
            'company' => $company,
            'user' => $user,
            'customer' => $customer,
            'vendor' => $vendor,
            'order' => $order,
        ];
    }
}
