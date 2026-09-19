<?php

namespace Tests\Feature;

use App\Auth\PortalSession;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\ReportService;
use App\Services\StatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Token;
use Tests\Support\AgencyOAuthTokens;
use Tests\TestCase;

class AgencyMcpTest extends TestCase
{
    use AgencyOAuthTokens;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureOAuthKeys();
    }

    public function test_endpoint_requires_dedicated_valid_token_and_discovers_only_five_read_tools(): void
    {
        $user = $this->agency();
        $this->rpc(null, 'tools/list')->assertUnauthorized();
        $this->rpc('invalid', 'tools/list')->assertUnauthorized();
        $this->rpc($user->createToken('web')->plainTextToken, 'tools/list')->assertUnauthorized();
        $this->rpc($this->token($user, ['other:read']), 'tools/list')->assertForbidden();
        $this->rpc($this->token($user, expires: new \DateTimeImmutable('-1 minute')), 'tools/list')->assertUnauthorized();

        $token = $this->token($user);
        $this->rpc($token, 'initialize', [
            'protocolVersion' => '2025-03-26', 'capabilities' => (object) [],
            'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
        ])->assertOk()->assertJsonPath('result.serverInfo.name', 'InYice Agency Accounts');
        $tools = $this->rpc($token, 'tools/list')->assertOk()->json('result.tools');
        $this->assertEqualsCanonicalizing([
            'search_customers', 'list_outstanding_invoices', 'get_customer_statement', 'get_account_balance', 'get_profit_report',
        ], array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint']);
        }
        $this->rpc($token, 'tools/call', ['name' => 'record_payment', 'arguments' => (object) []])
            ->assertJsonPath('error.code', -32602);
    }

    public function test_current_user_role_company_and_tenant_status_are_enforced(): void
    {
        $user = $this->agency();
        $token = $this->token($user);
        foreach (['sales', 'super-admin'] as $role) {
            $user->role->update(['code' => $role, 'is_system' => $role === 'super-admin']);
            $this->rpc($token, 'tools/list')->assertForbidden();
        }
        foreach (['owner', 'admin', 'accounts'] as $role) {
            $user->role->update(['code' => $role, 'is_system' => false]);
            $this->rpc($token, 'tools/list')->assertOk();
        }
        foreach ([$user, $user->company, $user->tenant] as $model) {
            $model->update(['is_active' => false]);
            $this->rpc($token, 'tools/list')->assertStatus($model instanceof User ? 401 : 403);
            $model->update(['is_active' => true]);
            $token = $this->token($user);
        }
        $user->update(['email_verified_at' => null]);
        $this->rpc($token, 'tools/list')->assertUnauthorized();
    }

    public function test_customers_are_scoped_and_paginated_and_extra_scope_arguments_are_rejected(): void
    {
        $user = $this->agency();
        $first = $this->customer($user, 'Alpha');
        $second = $this->customer($user, 'Beta');
        $otherTenant = $this->agency();
        $sameTenant = $this->agency($user->tenant);
        $this->customer($otherTenant, 'External tenant secret');
        $this->customer($sameTenant, 'External company secret');
        $token = $this->token($user);

        $this->callTool($token, 'search_customers', ['per_page' => 1])
            ->assertJsonPath('result.structuredContent.data.0.id', $first->id)
            ->assertJsonPath('result.structuredContent.has_more', true);
        $this->callTool($token, 'search_customers', ['per_page' => 1, 'page' => 2])
            ->assertJsonPath('result.structuredContent.data.0.id', $second->id)
            ->assertJsonPath('result.structuredContent.has_more', false);
        $this->callTool($token, 'search_customers', ['search' => 'Beta'])
            ->assertJsonCount(1, 'result.structuredContent.data');
        $this->callTool($token, 'search_customers', ['search' => 'External'])
            ->assertJsonCount(0, 'result.structuredContent.data');
        foreach ([['company_id' => $sameTenant->company_id], ['tenant_id' => $otherTenant->tenant_id], ['per_page' => 101], ['page' => 0]] as $args) {
            $this->callTool($token, 'search_customers', $args)->assertJsonPath('result.isError', true);
        }
    }

    public function test_outstanding_invoices_exclude_paid_void_cancelled_and_other_agencies(): void
    {
        $user = $this->agency();
        $customer = $this->customer($user);
        $open = $this->invoice($user, $customer);
        foreach (['void', 'cancel', 'paid'] as $status) {
            $invoice = $this->invoice($user, $customer);
            $invoice->update(['status' => $status, 'outstanding_amount' => $status === 'paid' ? 0 : 100]);
        }
        foreach ([$this->agency(), $this->agency($user->tenant)] as $other) {
            $this->invoice($other, $this->customer($other));
        }
        $this->callTool($this->token($user), 'list_outstanding_invoices')
            ->assertJsonCount(1, 'result.structuredContent.data')
            ->assertJsonPath('result.structuredContent.data.0.id', $open->id)
            ->assertJsonPath('result.structuredContent.data.0.outstanding_amount', '100.0000');
    }

    public function test_balances_use_ledger_and_allow_discovery_without_exposing_bank_number(): void
    {
        $user = $this->agency();
        $cash = $this->cash($user);
        $bank = BankAccount::create([
            'uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id, 'company_id' => $user->company_id,
            'bank_name' => 'Agency bank', 'account_number' => 'SECRET-NUMBER', 'account_holder' => 'Agency',
            'currency_code' => 'PKR', 'opening_balance' => 500, 'is_active' => true,
        ]);
        $ledger = app(LedgerService::class);
        $ledger->recordCashDeposit($user->tenant_id, $cash->id, 50);
        $ledger->recordCashWithdrawal($user->tenant_id, $cash->id, 20);
        $ledger->recordBankDeposit($user->tenant_id, $bank->id, 25);
        $token = $this->token($user);
        $this->callTool($token, 'get_account_balance', ['account_type' => 'cash'])
            ->assertJsonPath('result.structuredContent.data.0.current_balance', 130);
        $this->callTool($token, 'get_account_balance', ['account_type' => 'bank', 'account_id' => $bank->id])
            ->assertJsonPath('result.structuredContent.data.current_balance', 525)
            ->assertJsonPath('result.structuredContent.data.account_name', 'Agency bank')
            ->assertDontSee('SECRET-NUMBER');
        $this->callTool($token, 'get_account_balance', ['account_type' => 'invalid'])->assertJsonPath('result.isError', true);
    }

    public function test_direct_ids_cannot_read_another_company_or_tenant(): void
    {
        $user = $this->agency();
        $token = $this->token($user);
        foreach ([$this->agency(), $this->agency($user->tenant)] as $other) {
            $customer = $this->customer($other, 'Secret');
            $cash = $this->cash($other);
            foreach ([
                ['get_customer_statement', ['customer_id' => $customer->id] + $this->dates()],
                ['list_outstanding_invoices', ['customer_id' => $customer->id]],
                ['get_account_balance', ['account_type' => 'cash', 'account_id' => $cash->id]],
            ] as [$name, $args]) {
                $this->callTool($token, $name, $args)->assertJsonPath('result.isError', true)
                    ->assertJsonPath('result.content.0.text', 'Record not found in your agency.');
            }
        }
    }

    public function test_statement_and_profit_match_existing_services_and_validate_date_ranges(): void
    {
        $user = $this->agency();
        $customer = $this->customer($user);
        $this->invoice($user, $customer);
        foreach ([$this->agency(), $this->agency($user->tenant)] as $other) {
            $this->invoice($other, $this->customer($other, 'Hidden report customer'));
        }
        $token = $this->token($user);
        $statement = app(StatementService::class)->customerStatement($user->tenant_id, $user->company_id, $customer->id, '2026-09-01', '2026-09-30');
        $profit = app(ReportService::class)->profitReport($user->tenant_id, $user->company_id, '2026-09-01', '2026-09-30');
        $result = $this->callTool($token, 'get_customer_statement', ['customer_id' => $customer->id] + $this->dates())
            ->assertJsonPath('result.isError', false)->assertDontSee('Hidden report customer');
        $this->assertEquals(json_decode(json_encode($statement), true), $result->json('result.structuredContent'));
        $result = $this->callTool($token, 'get_profit_report', $this->dates())
            ->assertJsonPath('result.isError', false)->assertDontSee('Hidden report customer');
        $this->assertEquals(json_decode(json_encode($profit), true), $result->json('result.structuredContent'));
        $this->assertSame(1, $result->json('result.structuredContent.summary.total_orders'));

        foreach ([[], ['from_date' => 'bad', 'to_date' => 'bad'], ['from_date' => '2026-09-30', 'to_date' => '2026-09-01'], ['from_date' => '2020-01-01', 'to_date' => '2026-01-01']] as $args) {
            $this->callTool($token, 'get_profit_report', $args)->assertJsonPath('result.isError', true);
        }
    }

    public function test_mcp_token_cannot_use_portal_apis_but_normal_token_still_can(): void
    {
        $user = $this->agency();
        $token = $this->token($user);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/customers')->assertUnauthorized();
        $this->withToken($token)->postJson('/api/v1/customers', ['name' => 'Must not be created'])->assertUnauthorized();
        $this->assertDatabaseMissing('customers', ['name' => 'Must not be created']);
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('web')->plainTextToken)->getJson('/api/v1/customers')->assertOk();
    }

    public function test_mcp_token_does_not_block_browser_login_and_revocation_stops_access(): void
    {
        $user = $this->agency();
        $token = $this->token($user);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123'])->assertOk();
        $this->rpc($token, 'tools/list')->assertOk();
        Token::where('user_id', $user->id)->update(['revoked' => true]);
        $this->rpc($token, 'tools/list')->assertUnauthorized();
    }

    public function test_starter_has_portal_access_but_cannot_use_external_api_credentials(): void
    {
        $user = $this->agency();
        $user->company->update(['is_paid' => false]);
        $external = $user->createToken('integration')->plainTextToken;
        $legacyWeb = $user->createToken('web')->plainTextToken;
        foreach ([$external, $legacyWeb] as $token) {
            $this->apiRequest($token)->assertForbidden();
        }
        $user->company->update(['is_paid' => true]);
        $this->apiRequest($external)->assertOk();
        $user->company->update(['is_paid' => false]);
        $this->apiRequest($external)->assertForbidden();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'password123', 'force_logout' => true,
        ])->assertOk();
        $cookie = $login->getCookie(PortalSession::COOKIE, false);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $portal = $login->json('token');
        $this->apiRequest($portal, $cookie->getValue())->assertOk();
        $this->withToken($portal)->postJson('/api/v1/customers', ['name' => 'Portal customer', 'type' => 'B2C', 'currency_code' => 'PKR'])->assertCreated();
        $this->apiRequest($portal)->assertUnauthorized();
        $this->apiRequest($portal, 'tampered')->assertUnauthorized();
        $otherToken = $user->createToken('web', ['portal:session'])->plainTextToken;
        $this->apiRequest($otherToken, $cookie->getValue())->assertUnauthorized();
        $this->rpc($portal, 'tools/list')->assertUnauthorized();
        $this->rpc($this->token($user), 'tools/list')->assertForbidden();
    }

    public function test_legacy_read_only_mcp_token_cannot_gain_portal_write_access(): void
    {
        $user = $this->agency();
        $token = $user->createToken('legacy', ['agency:read'])->plainTextToken;
        $this->apiRequest($token)->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/customers', ['name' => 'Forbidden'])->assertForbidden();
        $this->assertDatabaseMissing('customers', ['name' => 'Forbidden']);
    }

    private function apiRequest(string $token, string $cookie = ''): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->withCredentials()->withUnencryptedCookie(PortalSession::COOKIE, $cookie)
            ->withToken($token)->getJson('/api/v1/customers');
    }

    private function rpc(?string $token, string $method, array $params = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/mcp/agency', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ?: (object) []],
            ['Authorization' => $token ? 'Bearer '.$token : '', 'Accept' => 'application/json, text/event-stream']);
    }

    private function callTool(string $token, string $name, array $args = []): TestResponse
    {
        return $this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => $args ?: (object) []])->assertOk();
    }

    private function dates(): array
    {
        return ['from_date' => '2026-09-01', 'to_date' => '2026-09-30'];
    }

    private function agency(?Tenant $tenant = null): User
    {
        $tenant ??= Tenant::create(['uid' => (string) Str::ulid(), 'code' => Str::random(8), 'name' => 'Agency tenant', 'is_active' => true]);
        $company = Company::create([
            'uid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'legal_name' => 'Agency', 'display_name' => 'Agency',
            'base_currency_code' => 'PKR', 'default_timezone' => 'UTC', 'is_active' => true, 'is_paid' => true,
        ]);
        $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => 'admin'], ['uid' => (string) Str::ulid(), 'name' => 'Admin', 'is_system' => false]);

        return User::create([
            'uid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'role_id' => $role->id,
            'name' => 'Agency user', 'email' => Str::ulid().'@example.com', 'password' => 'password123', 'email_verified_at' => now(), 'is_active' => true,
        ]);
    }

    private function customer(User $user, string $name = 'Agency customer'): Customer
    {
        return Customer::create([
            'uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id, 'company_id' => $user->company_id,
            'name' => $name, 'type' => 'B2C', 'currency_code' => 'PKR', 'is_active' => true,
        ]);
    }

    private function cash(User $user): CashAccount
    {
        return CashAccount::create([
            'uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id, 'company_id' => $user->company_id,
            'account_code' => Str::random(8), 'account_name' => 'Agency cash', 'currency_code' => 'PKR', 'opening_balance' => 100, 'is_active' => true,
        ]);
    }

    private function invoice(User $user, Customer $customer): Invoice
    {
        $order = Order::create([
            'uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id, 'company_id' => $user->company_id, 'customer_id' => $customer->id,
            'order_number' => (string) Str::ulid(), 'currency_code' => 'PKR', 'status' => 'order', 'total_amount' => 100,
            'created_by_user_id' => $user->id, 'updated_by_user_id' => $user->id,
        ]);

        return Invoice::create([
            'uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id, 'company_id' => $user->company_id, 'customer_id' => $customer->id,
            'order_id' => $order->id, 'invoice_number' => (string) Str::ulid(), 'invoice_date' => '2026-09-10', 'due_date' => '2026-09-20',
            'currency_code' => 'PKR', 'subtotal' => 100, 'total_amount' => 100, 'outstanding_amount' => 100, 'status' => 'issued',
        ]);
    }
}
