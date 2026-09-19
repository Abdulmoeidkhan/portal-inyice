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
use App\Notifications\WelcomeVerifyEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthSecurityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_cookie_uses_current_host_even_with_a_different_session_domain(): void
    {
        $ctx = $this->seedContext('admin');
        config(['session.domain' => 'os.inyice.com']);
        app('cookie')->setDefaultPathAndDomain('/', 'os.inyice.com', true, 'lax');
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $ctx['user']->email, 'password' => 'password123',
        ])->assertOk();
        $cookie = $login->getCookie(\App\Auth\PortalSession::COOKIE, false);
        $this->assertNull($cookie->getDomain());
        $this->assertSame('/api/v1', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
        $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->withToken($login->json('token'))->getJson('/api/v1/user')->assertOk();
        $logout = $this->postJson('/api/v1/auth/logout')->assertOk();
        $expired = $logout->getCookie(\App\Auth\PortalSession::COOKIE, false);
        $this->assertNull($expired->getDomain());
        $this->assertSame($cookie->getPath(), $expired->getPath());
        $this->assertLessThan(time(), $expired->getExpiresTime());
    }

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

        $this->withCredentials()->withUnencryptedCookie(\App\Auth\PortalSession::COOKIE, $forcedLogin->getCookie(\App\Auth\PortalSession::COOKIE, false)->getValue())
            ->withHeader('Authorization', 'Bearer ' . $forcedLogin->json('token'))
            ->getJson('/api/v1/user')
            ->assertOk();
    }

    public function test_signin_requires_verified_email_and_can_resend_verification_email(): void
    {
        Notification::fake();

        $ctx = $this->seedContext('admin', false);

        $this->postJson('/api/v1/auth/login', [
            'email' => $ctx['user']->email,
            'password' => 'password123',
        ])->assertStatus(403)
            ->assertJsonPath('email_unverified', true);

        $this->postJson('/api/v1/auth/email/verification-notification', [
            'email' => $ctx['user']->email,
        ])->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($ctx['user'], WelcomeVerifyEmail::class);
    }

    public function test_signed_email_verification_link_marks_user_verified(): void
    {
        Notification::fake();

        $ctx = $this->seedContext('admin', false);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $ctx['user']->id,
            'hash' => sha1($ctx['user']->email),
        ]);

        $this->get($url)->assertRedirect('/login?verified=1');

        $this->assertNotNull($ctx['user']->fresh()->email_verified_at);
    }

    public function test_active_user_can_request_and_complete_password_reset(): void
    {
        Notification::fake();

        $ctx = $this->seedContext('admin');
        $token = null;

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => $ctx['user']->email,
        ])->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($ctx['user'], ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return is_string($token) && $token !== '';
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $ctx['user']->email,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('new-password123', $ctx['user']->fresh()->password));
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
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => "unknown{$i}@test.local",
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $limited = $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@test.local',
            'password' => 'wrong-password',
        ]);

        $limited->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'unknown@test.local',
        ])->assertOk();

        $this->travel(24)->hours();
        $this->travel(1)->seconds();
        $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@test.local',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_correct_credentials_do_not_consume_failed_signin_allowance(): void
    {
        $ctx = $this->seedContext('admin');
        $credentials = ['email' => $ctx['user']->email, 'password' => 'password123'];

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', $credentials + ['force_logout' => true])->assertOk();
        }
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', $credentials)->assertStatus(409);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $ctx['user']->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', $credentials)->assertStatus(429);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_signin_limit_is_shared_by_ipv6_network_but_not_other_networks(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "2001:db8:1:1::{$i}"])
                ->postJson('/api/v1/auth/login', [
                    'email' => "unknown{$i}@test.local",
                    'password' => 'wrong-password',
                ])->assertStatus(422);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:1::6'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'unknown@test.local', 'password' => 'wrong-password',
            ])->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::1'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'unknown@test.local', 'password' => 'wrong-password',
            ])->assertStatus(422);
    }

    public function test_signup_is_rate_limited_after_two_attempts_even_with_different_emails(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $csrfToken = $this->getJson('/registration/session')->assertOk()->json('csrf_token');
            $this->postJson('/registration/register', ['admin_email' => "owner{$i}@example.test"], [
                'X-CSRF-TOKEN' => $csrfToken,
            ])->assertStatus(422);
        }

        $this->postJson('/registration/register', ['admin_email' => 'another@example.test'], [
            'X-CSRF-TOKEN' => $csrfToken,
        ])->assertStatus(429)->assertHeader('Retry-After');
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
                    'vendor_id' => $ctx['vendor']->id,
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
    private function seedContext(string $roleCode, bool $verified = true): array
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
            'email_verified_at' => $verified ? now() : null,
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
