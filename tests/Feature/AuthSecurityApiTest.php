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

        $paymentAttempt = $this->postJson('/api/v1/payments/record', [
            'invoice_uid' => $invoiceUid,
            'amount' => 200,
            'payment_method' => 'cash',
        ]);

        $paymentAttempt->assertStatus(403);
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
