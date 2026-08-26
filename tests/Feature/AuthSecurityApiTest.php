<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
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

class AuthSecurityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_signin_returns_token_for_valid_credentials(): void
    {
        $ctx = $this->seedContext('admin');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $ctx['user']->email,
            'password' => 'password123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('user.email', $ctx['user']->email);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_signin_requires_confirmation_before_replacing_active_session(): void
    {
        $ctx = $this->seedContext('admin');

        $firstLogin = $this->postJson('/api/v1/auth/login', [
            'email' => $ctx['user']->email,
            'password' => 'password123',
        ]);
        $firstLogin->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $ctx['user']->email,
            'password' => 'password123',
        ])->assertStatus(409)
            ->assertJsonPath('session_conflict', true);

        $forcedLogin = $this->postJson('/api/v1/auth/login', [
            'email' => $ctx['user']->email,
            'password' => 'password123',
            'force_logout' => true,
        ]);
        $forcedLogin->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withHeader('Authorization', 'Bearer ' . $firstLogin->json('token'))
            ->getJson('/api/v1/user')
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer ' . $forcedLogin->json('token'))
            ->getJson('/api/v1/user')
            ->assertOk();
    }

    public function test_authenticated_user_can_update_profile_name(): void
    {
        $ctx = $this->seedContext('admin');

        Sanctum::actingAs($ctx['user']);

        $this->patchJson('/api/v1/user', [
            'name' => 'Updated Profile Name',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.name', 'Updated Profile Name')
            ->assertJsonPath('user.email', $ctx['user']->email);

        $this->assertDatabaseHas('users', [
            'id' => $ctx['user']->id,
            'name' => 'Updated Profile Name',
        ]);
    }

    public function test_signin_is_rate_limited_after_too_many_attempts(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'unknown@test.local',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $limited = $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@test.local',
            'password' => 'wrong-password',
        ]);

        $limited->assertStatus(429);
    }

    public function test_signup_is_rate_limited_after_too_many_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/registration/register', [])->assertStatus(422);
        }

        $limited = $this->postJson('/api/v1/registration/register', []);
        $limited->assertStatus(429);
    }

    public function test_sales_role_cannot_record_payment(): void
    {
        $ctx = $this->seedContext('sales');

        Sanctum::actingAs($ctx['user']);

        $createInvoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ]);
        $createInvoice->assertCreated();

        $invoiceUid = $createInvoice->json('invoice.uid');

        $paymentAttempt = $this->postJson('/api/v1/receipts/customer/record', [
            'invoice_uid' => $invoiceUid,
            'amount' => 200,
            'payment_method' => 'cash',
        ]);

        $paymentAttempt->assertStatus(403);
    }

    public function test_sales_role_cannot_change_status_after_order_enters_invoice_section(): void
    {
        $ctx = $this->seedContext('sales');
        $ctx['order']->update(['status' => 'invoice']);

        Sanctum::actingAs($ctx['user']);

        $this->patchJson('/api/v1/orders/' . $ctx['order']->uid, [
            'customer_id' => $ctx['customer']->id,
            'status' => 'refund',
            'currency_code' => 'PKR',
            'total_amount' => 500,
            'notes' => 'Attempt status change',
        ])->assertStatus(403)
            ->assertJsonPath('error', 'Sales staff cannot change order status after it enters the invoice section.');

        $this->patchJson('/api/v1/orders/' . $ctx['order']->uid, [
            'customer_id' => $ctx['customer']->id,
            'status' => 'invoice',
            'currency_code' => 'PKR',
            'total_amount' => 500,
            'notes' => 'Allowed non-status update',
        ])->assertOk()
            ->assertJsonPath('order.status', 'invoice')
            ->assertJsonPath('order.notes', 'Allowed non-status update')
            ->assertJsonPath('invoice', null);

        $this->assertSame(0, Invoice::count());
    }

    public function test_company_setting_controls_sales_cost_profit_write_access(): void
    {
        $ctx = $this->seedContext('sales');
        Sanctum::actingAs($ctx['user']);

        $payload = [
            'customer_id' => $ctx['customer']->id,
            'status' => 'order',
            'currency_code' => 'PKR',
            'total_amount' => 750,
            'notes' => 'Sales cost access check',
            'voucher' => [
                'voucher_no' => 'COST-CHECK-001',
                'active_sections' => ['flights'],
                'flights' => [],
                'passengers' => [],
                'pricing' => [[
                    'pax_name' => 'Cost Tester',
                    'flight_cost' => 500,
                    'flight_profit' => 250,
                    'flight_sales' => 750,
                ]],
            ],
        ];

        $this->patchJson('/api/v1/orders/' . $ctx['order']->uid, $payload)
            ->assertOk()
            ->assertJsonPath('order.meta.pricing.0.flight_sales', 750)
            ->assertJsonMissingPath('order.meta.pricing.0.flight_cost')
            ->assertJsonMissingPath('order.meta.pricing.0.flight_profit');

        $this->assertArrayNotHasKey('flight_cost', $ctx['order']->fresh()->meta['pricing'][0]);

        $ctx['company']->update(['sales_can_edit_cost' => true]);

        $this->patchJson('/api/v1/orders/' . $ctx['order']->uid, $payload)
            ->assertOk()
            ->assertJsonPath('order.meta.pricing.0.flight_cost', 500)
            ->assertJsonPath('order.meta.pricing.0.flight_profit', 250)
            ->assertJsonPath('order.meta.pricing.0.flight_sales', 750);

        $savedPricing = $ctx['order']->fresh()->meta['pricing'][0];
        $this->assertSame(500, $savedPricing['flight_cost']);
        $this->assertSame(250, $savedPricing['flight_profit']);
    }

    public function test_edit_locks_block_other_users_until_released(): void
    {
        $ctx = $this->seedContext('admin');
        $otherUser = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $ctx['tenant']->id,
            'company_id' => $ctx['company']->id,
            'role_id' => $ctx['role']->id,
            'name' => 'Second User',
            'email' => 'second-' . fake()->unique()->safeEmail(),
            'password' => 'password123',
            'is_active' => true,
        ]);
        $invoice = Invoice::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $ctx['tenant']->id,
            'company_id' => $ctx['company']->id,
            'order_id' => $ctx['order']->id,
            'customer_id' => $ctx['customer']->id,
            'invoice_number' => 'INV-' . fake()->unique()->numerify('######'),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'currency_code' => 'PKR',
            'subtotal' => 500,
            'tax_amount' => 0,
            'total_amount' => 500,
            'outstanding_amount' => 500,
            'advance_balance' => 0,
            'status' => 'issued',
            'fx_rate_to_base' => 1,
        ]);

        Sanctum::actingAs($ctx['user']);
        $this->postJson('/api/v1/edit-locks', [
            'type' => 'order',
            'uid' => $ctx['order']->uid,
        ])->assertOk()
            ->assertJsonPath('locked', false);

        Sanctum::actingAs($otherUser);
        $this->postJson('/api/v1/edit-locks', [
            'type' => 'order',
            'uid' => $ctx['order']->uid,
        ])->assertStatus(423)
            ->assertJsonPath('locked_by.name', 'Security User');

        Sanctum::actingAs($ctx['user']);
        $this->patchJson('/api/v1/edit-locks', [
            'type' => 'order',
            'uid' => $ctx['order']->uid,
        ])->assertOk();
        $this->deleteJson('/api/v1/edit-locks', [
            'type' => 'order',
            'uid' => $ctx['order']->uid,
        ])->assertOk();

        Sanctum::actingAs($otherUser);
        $this->postJson('/api/v1/edit-locks', [
            'type' => 'order',
            'uid' => $ctx['order']->uid,
        ])->assertOk()
            ->assertJsonPath('locked', false);

        $this->deleteJson('/api/v1/edit-locks', [
            'type' => 'order',
            'uid' => $ctx['order']->uid,
        ])->assertOk();

        Sanctum::actingAs($ctx['user']);
        $this->postJson('/api/v1/edit-locks', [
            'type' => 'invoice',
            'uid' => $invoice->uid,
        ])->assertOk()
            ->assertJsonPath('locked', false);

        Sanctum::actingAs($otherUser);
        $this->postJson('/api/v1/edit-locks', [
            'type' => 'invoice',
            'uid' => $invoice->uid,
        ])->assertStatus(423)
            ->assertJsonPath('locked_by.name', 'Security User');
    }

    /**
     * @return array<string, mixed>
     */
    private function seedContext(string $roleCode): array
    {
        $tenant = Tenant::create([
            'uid' => (string) Str::ulid(),
            'code' => 'sec' . fake()->unique()->numerify('###'),
            'name' => 'Security Tenant',
            'is_active' => true,
        ]);

        $company = Company::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'legal_name' => 'Security Company Legal',
            'display_name' => 'Security Company',
            'email' => 'company-' . fake()->unique()->safeEmail(),
            'phone' => '+92-300-5555555',
            'address' => 'Karachi, Pakistan',
            'base_currency_code' => 'PKR',
            'default_timezone' => 'Asia/Karachi',
            'is_active' => true,
        ]);

        $role = Role::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'code' => $roleCode,
            'name' => ucfirst($roleCode),
            'is_system' => false,
        ]);

        $user = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
            'name' => 'Security User',
            'email' => 'user-' . fake()->unique()->safeEmail(),
            'password' => 'password123',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => 'B2C',
            'name' => 'Sec Customer',
            'currency_code' => 'PKR',
            'is_active' => true,
        ]);

        $vendor = Vendor::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => 'B2B',
            'name' => 'Sec Vendor',
            'currency_code' => 'PKR',
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
            'status' => 'order',
            'currency_code' => 'PKR',
            'total_amount' => 500,
        ]);

        OrderItem::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'description' => 'Security item',
            'quantity' => 1,
            'unit_price' => 500,
            'total_price' => 500,
        ]);

        return [
            'tenant' => $tenant,
            'company' => $company,
            'user' => $user,
            'role' => $role,
            'customer' => $customer,
            'vendor' => $vendor,
            'order' => $order,
        ];
    }
}
