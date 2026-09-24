<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DeveloperDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeveloperAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_authenticated_business_write_is_forbidden_for_developers(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || $route->uri() === 'api/v1/auth/logout'
                || ! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }
            foreach (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) as $method) {
                $uri = '/'.preg_replace('/\{[^}]+\}/', 'missing-record', $route->uri());
                $this->json($method, $uri, [])->assertForbidden();
                $checked++;
            }
        }
        $this->assertGreaterThan(50, $checked);
    }

    public function test_developer_can_read_financial_routes_but_not_internal_provider_data(): void
    {
        Sanctum::actingAs($this->user());
        foreach (['orders', 'invoices', 'customers', 'vendors', 'company-users', 'accounts/cash', 'accounts/bank', 'profit-shares', 'reports/aging'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertOk();
        }
        $this->getJson('/api/v1/reports/cancelled?from_date=2026-01-01&to_date=2026-12-31')->assertOk();
        $this->getJson('/api/v1/internal/companies')->assertForbidden();
    }

    public function test_export_is_dev_only_and_never_contains_other_companies_or_credentials(): void
    {
        $dev = $this->user();
        $sameTenant = $this->user('dev', $dev->tenant_id);
        $otherTenant = $this->user();
        $own = $this->order($dev, 'OWN');
        $this->order($sameTenant, 'OTHER-COMPANY');
        $this->order($otherTenant, 'OTHER-TENANT');
        Sanctum::actingAs($dev);

        $this->getJson('/api/v1/dev/data/orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/v1/dev/data/order_items')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/dev/data/personal_access_tokens')->assertNotFound();
        $this->getJson('/api/v1/dev/data/orders?per_page=101')->assertUnprocessable();

        $response = $this->getJson('/api/v1/dev/export')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $json = $response->streamedContent();
        $export = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(array_keys(DeveloperDataService::FIELDS), array_keys($export['data']));
        $this->assertCount(1, $export['data']['orders']);
        $this->assertCount(1, $export['data']['order_items']);
        $this->assertSame($dev->company->uid, $export['company_uid']);
        foreach (['OTHER-COMPANY', 'OTHER-TENANT', 'share_token', 'password', 'auth_provider', 'personal_access_tokens'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }

        foreach (['owner', 'admin', 'accounts', 'sales'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/v1/dev/export')->assertForbidden();
            $this->getJson('/api/v1/dev/data')->assertForbidden();
            $this->getJson('/api/v1/dev/data/orders')->assertForbidden();
        }
    }

    public function test_documentation_covers_roles_and_matches_restrictions(): void
    {
        foreach (['owner', 'admin', 'accounts', 'sales', 'dev'] as $role) {
            Sanctum::actingAs($this->user($role));
            $response = $this->getJson('/api/v1/api-documentation')->assertOk();
            $endpoints = collect($response->json('endpoints'))->keyBy('id');
            $this->assertSame(['dev'], $endpoints['GET:api/v1/dev/export']['roles']);
            $this->assertSame(['owner'], $endpoints['POST:api/v1/company-profile']['roles']);
            $this->assertNotContains('dev', $endpoints['POST:api/v1/receivings']['roles']);
            $this->assertContains('dev', $endpoints['GET:api/v1/reports/aging']['roles']);
            $this->assertSame(['owner', 'admin', 'dev'], $endpoints['GET:api/v1/reports/cancelled']['roles']);
            $this->assertFalse($endpoints->contains(fn ($endpoint) => str_contains($endpoint['path'], '/internal/')));
            $this->assertContains('email', array_column($endpoints['POST:api/v1/auth/login']['fields'], 'name'));
        }
    }

    public function test_paid_developer_can_sign_in_for_read_only_api_access_and_sign_out(): void
    {
        $dev = $this->user();
        $dev->company->update(['is_paid' => true]);
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $dev->email, 'password' => 'password123', 'access_mode' => 'api',
        ])->assertOk();
        $this->assertSame(['data:read'], $dev->tokens()->first()->abilities);
        $this->withToken($response->json('token'))->getJson('/api/v1/dev/data')->assertOk();
        $this->withToken($response->json('token'))->postJson('/api/v1/receivings', [])->assertForbidden();
        $this->withToken($response->json('token'))->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $dev->tokens()->count());
    }

    public function test_api_login_preserves_plan_and_role_restrictions(): void
    {
        foreach (['dev', 'owner'] as $role) {
            $user = $this->user($role);
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email, 'password' => 'password123', 'access_mode' => 'api',
            ])->assertForbidden();
        }
    }

    public function test_read_only_api_token_cannot_gain_write_access_after_role_change(): void
    {
        $admin = $this->user('admin');
        $admin->company->update(['is_paid' => true]);
        $token = $admin->createToken('dev-api', ['data:read'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/customers', [])->assertForbidden();
    }

    public function test_ledger_export_scopes_accounts_by_company_and_account_type(): void
    {
        $dev = $this->user();
        $other = $this->user('dev', $dev->tenant_id);
        foreach ([$dev, $other] as $user) {
            $accountId = DB::table('cash_accounts')->insertGetId([
                'uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id, 'company_id' => $user->company_id,
                'account_code' => 'CASH', 'account_name' => 'Cash', 'currency_code' => 'PKR',
            ]);
            foreach (['cash', 'bank'] as $type) {
                DB::table('ledger_entries')->insert([
                    'uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id,
                    'account_id' => $accountId, 'account_type' => $type,
                    'entry_date' => '2026-09-24', 'created_at' => now(),
                ]);
            }
        }
        Sanctum::actingAs($dev);
        $this->getJson('/api/v1/dev/data/ledger_entries')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.account_type', 'cash');
    }

    public function test_export_requires_authentication_and_active_account(): void
    {
        $this->getJson('/api/v1/dev/export')->assertUnauthorized();
        $this->getJson('/api/v1/api-documentation')->assertUnauthorized();
        $dev = $this->user();
        $dev->update(['is_active' => false]);
        Sanctum::actingAs($dev);
        $this->getJson('/api/v1/dev/export')->assertForbidden();
    }

    public function test_existing_tenants_receive_dev_role_idempotently(): void
    {
        $user = $this->user('owner');
        $migration = require database_path('migrations/2026_09_24_000001_add_developer_role.php');
        $migration->up();
        $migration->up();
        $this->assertSame(1, Role::where('tenant_id', $user->tenant_id)->where('code', 'dev')->count());
    }

    private function user(string $role = 'dev', ?int $tenantId = null): User
    {
        Notification::fake();
        $tenantId ??= Tenant::create(['uid' => (string) Str::ulid(), 'code' => Str::random(12), 'name' => 'Developer test', 'is_active' => true])->id;
        $company = Company::create([
            'uid' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'legal_name' => 'Test', 'display_name' => 'Test',
            'base_currency_code' => 'PKR', 'default_timezone' => 'Asia/Karachi', 'is_active' => true,
        ]);
        $roleModel = Role::firstOrCreate(['tenant_id' => $tenantId, 'code' => $role], ['uid' => (string) Str::ulid(), 'name' => $role, 'is_system' => false]);

        return User::create([
            'uid' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'company_id' => $company->id, 'role_id' => $roleModel->id,
            'name' => 'Developer', 'email' => Str::ulid().'@example.com', 'password' => 'password123', 'email_verified_at' => now(), 'is_active' => true,
        ]);
    }

    private function order(User $user, string $marker): Order
    {
        $customer = Customer::create(['uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id, 'company_id' => $user->company_id, 'type' => 'B2C', 'name' => $marker, 'currency_code' => 'PKR']);
        $order = Order::create([
            'uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id, 'company_id' => $user->company_id,
            'customer_id' => $customer->id, 'order_number' => $marker, 'status' => 'order', 'currency_code' => 'PKR', 'total_amount' => 100,
            'created_by_user_id' => $user->id, 'updated_by_user_id' => $user->id,
            'share_token' => Str::random(64),
        ]);
        OrderItem::create(['uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id, 'order_id' => $order->id, 'description' => $marker, 'quantity' => 1, 'unit_price' => 100, 'total_price' => 100]);

        return $order;
    }
}
