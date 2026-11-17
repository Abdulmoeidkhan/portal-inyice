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
use App\Services\Accounting\Books;
use App\Services\AgencyDataService;
use App\Services\AgencyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AgencyOAuthTokens;
use Tests\TestCase;

class AgencyDataApiTest extends TestCase
{
    use AgencyOAuthTokens;
    use RefreshDatabase;

    public function test_all_resources_have_working_scoped_read_queries_and_no_credential_fields(): void
    {
        $user = $this->agency();
        $service = app(AgencyDataService::class);
        Sanctum::actingAs($user);
        foreach ($service->catalog($user)['resources'] as $resource) {
            $response = $this->getJson('/api/v1/data/'.$resource['name'])->assertOk();
            $response->assertJsonPath('resource', $resource['name'])->assertJsonPath('has_more', false);
            $this->assertEmpty(array_intersect($resource['fields'], ['password', 'remember_token', 'share_token', 'account_number', 'raw_text', 'tenant_id', 'company_id']));
        }
        $this->getJson('/api/v1/data')->assertOk()->assertJsonCount(count($service->fields()), 'resources');
    }

    public function test_company_scoping_parent_filters_pagination_and_deleted_records(): void
    {
        $user = $this->agency();
        $customer = $this->customer($user, 'Local customer');
        $local = $this->order($user, $customer, 'LOCAL');
        $deleted = $this->order($user, $customer, 'DELETED');
        $deleted->delete();
        foreach ([$this->agency($user->tenant), $this->agency()] as $foreign) {
            $this->order($foreign, $this->customer($foreign, 'FOREIGN SECRET'), 'FOREIGN SECRET');
        }
        $this->customer($user, 'Second customer');
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/data/orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.order_number', 'LOCAL')->assertDontSee('FOREIGN SECRET')->assertDontSee('share_token');
        $this->getJson('/api/v1/data/order_items?filter_field=order_id&filter_value='.$local->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/data/order_items')->assertOk()->assertJsonCount(1, 'data')->assertDontSee('DELETED');
        $this->getJson('/api/v1/data/customers?per_page=1')->assertOk()->assertJsonPath('has_more', true)->assertJsonPath('data.0.id', $customer->id);
        $this->getJson('/api/v1/data/customers?per_page=1&page=2')->assertOk()->assertJsonPath('has_more', false);
        $this->getJson('/api/v1/data/orders?uid='.$local->uid)->assertOk()->assertJsonCount(1, 'data');
        foreach (['company_id=1', 'tenant_id=1', 'per_page=101', 'page=0', 'filter_field=password&filter_value=x', 'filter_field=id', 'filter_value=1'] as $query) {
            $this->getJson('/api/v1/data/orders?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/v1/data/oauth_access_tokens')->assertNotFound();
    }

    public function test_accounting_children_exchange_rates_and_gds_stay_in_scope(): void
    {
        $local = $this->agency();
        $other = $this->agency($local->tenant);
        $external = $this->agency();
        foreach ([$local, $other, $external] as $user) {
            $book = app(Books::class)->setup($user, '2026-01-01');
            DB::table('gds_parsed_records')->insert(['uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id, 'raw_text' => 'RAW SECRET', 'gds_source' => 'test', 'parsed_by_user_id' => $user->id, 'booking_reference' => 'USER-'.$user->id]);
            if ($user !== $other) {
                DB::table('exchange_rates')->insert(['uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id, 'from_currency_code' => 'USD', 'to_currency_code' => 'PKR', 'rate' => 280, 'rate_date' => '2026-01-01']);
            }
        }
        Sanctum::actingAs($local);
        $this->getJson('/api/v1/data/accounting_books')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/data/accounting_accounts')->assertOk()->assertJsonCount(count(Books::ACCOUNTS), 'data');
        $this->getJson('/api/v1/data/accounting_audit_events')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/data/gds_parsed_records')->assertOk()->assertJsonCount(1, 'data')->assertDontSee('RAW SECRET')->assertJsonPath('data.0.booking_reference', 'USER-'.$local->id);
        $this->getJson('/api/v1/data/exchange_rates')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_roles_and_plan_restrictions_apply_to_catalogs_and_direct_reads(): void
    {
        $user = $this->agency();
        Sanctum::actingAs($user);
        $user->role->update(['code' => 'accounts']);
        $this->getJson('/api/v1/data/users')->assertForbidden();
        $this->getJson('/api/v1/data/roles')->assertForbidden();
        $this->getJson('/api/v1/data-reports/cancelled?from_date=2026-01-01&to_date=2026-01-31')->assertForbidden();
        $names = array_column($this->getJson('/api/v1/data')->assertOk()->json('resources'), 'name');
        $this->assertNotContains('users', $names);
        $user->role->update(['code' => 'dev']);
        $this->getJson('/api/v1/data/accounting_books')->assertForbidden();
        $this->getJson('/api/v1/data/users')->assertOk();
        $user->role->update(['code' => 'sales']);
        $this->getJson('/api/v1/data')->assertForbidden();
        $user->role->update(['code' => 'admin']);
        $user->company->update(['is_paid' => false]);
        $this->getJson('/api/v1/data/accounting_books')->assertForbidden();
    }

    public function test_mcp_and_rest_return_the_same_records_and_reject_extra_arguments(): void
    {
        $this->configureOAuthKeys();
        $user = $this->agency();
        $this->customer($user, 'Example customer');
        $token = $this->token($user);
        $expected = app(AgencyDataService::class)->read($user, 'customers', ['per_page' => 1]);
        $call = fn ($name, $args) => $this->postJson('/mcp/agency', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args ?: (object) []]], ['Authorization' => 'Bearer '.$token]);
        $call('read_agency_data', ['resource' => 'customers', 'per_page' => 1])->assertOk()->assertJsonPath('result.structuredContent', json_decode(json_encode($expected), true));
        $call('read_agency_data', ['resource' => 'customers', 'company_id' => 100])->assertJsonPath('result.isError', true);
        $call('read_agency_data', ['resource' => 'oauth_access_tokens'])->assertJsonPath('result.isError', true);
        $call('list_agency_resources', [])->assertOk()->assertJsonCount(count(app(AgencyDataService::class)->fields()), 'result.structuredContent.resources');
        $call('get_agency_report', ['report' => 'tax', 'from_date' => '2026-01-01', 'to_date' => '2026-01-31'])->assertOk()->assertJsonPath('result.structuredContent.period.from', '2026-01-01');
        $call('get_agency_report', ['report' => 'tax', 'from_date' => '2024-01-01', 'to_date' => '2026-01-31'])->assertJsonPath('result.isError', true);
        $call('get_agency_report', ['report' => 'tax', 'company_id' => 1])->assertJsonPath('result.isError', true);
    }

    public function test_every_report_executes_and_public_report_examples_are_synthetic(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 15)->startOfDay());
        $user = $this->agency();
        $customer = $this->customer($user, 'Example customer');
        $vendor = Vendor::create(['uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id, 'company_id' => $user->company_id, 'name' => 'Example vendor', 'type' => 'B2B', 'currency_code' => 'PKR']);
        app(Books::class)->setup($user, '2026-01-01');
        Sanctum::actingAs($user);
        $fixtures = [];
        foreach (app(AgencyReportService::class)->catalog($user) as $report) {
            $query = [];
            if (isset($report['parameters']['from_date'])) {
                $query = ['from_date' => '2026-01-01', 'to_date' => '2026-01-31'];
            }
            if ($report['name'] === 'customer_statement') {
                $query['customer_id'] = $customer->id;
            }
            if ($report['name'] === 'vendor_statement') {
                $query['vendor_id'] = $vendor->id;
            }
            $response = $this->getJson($report['path'].'?'.http_build_query($query))->assertOk()->json();
            $fixtures[$report['name']] = ['query' => $query, 'response' => $response];
        }
        // Opt-in regeneration runs only inside this isolated SQLite test database.
        if (getenv('UPDATE_API_EXAMPLES') === '1') {
            $this->assertSame(':memory:', config('database.connections.sqlite.database'));
            if (! is_dir(resource_path('api'))) {
                mkdir(resource_path('api'));
            }
            file_put_contents(resource_path('api/report-examples.json'), json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }
        $stored = json_decode(file_get_contents(resource_path('api/report-examples.json')), true);
        $this->assertEqualsCanonicalizing(array_keys(AgencyReportService::REPORTS), array_keys($stored));
        foreach ($fixtures as $name => $fixture) {
            $this->assertEqualsCanonicalizing(array_keys($fixture['response']), array_keys($stored[$name]['response']), $name);
        }
        $this->getJson('/api/v1/data-reports/profit?from_date=2024-01-01&to_date=2026-01-31')->assertUnprocessable();
        $this->getJson('/api/v1/data-reports/tax?from_date=2026-01-01&to_date=2026-01-31&company_id=100')->assertUnprocessable();
    }

    public function test_public_examples_and_mocks_need_no_auth_and_never_query_business_tables(): void
    {
        $catalog = $this->getJson('/api/v1/api-examples')->assertOk()->json();
        $this->get('/api-examples')->assertOk()->assertSee('Read-only API examples');
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        foreach ($catalog['examples'] as $entry) {
            $example = $this->getJson($entry['example_url'])->assertOk()->assertJsonPath('synthetic', true)->json();
            $this->getJson($entry['mock_url'])->assertOk()->assertHeader('X-InYice-Mock', 'true')->assertExactJson($example['response']['body']);
        }
        $this->assertEmpty($queries, implode("\n", $queries));
        $this->getJson('/api/v1/api-examples/unknown')->assertNotFound();
        $this->postJson('/api/v1/api-mocks/data.orders', [])->assertStatus(405);
        $this->getJson('/api/v1/data/orders')->assertUnauthorized();
    }

    private function agency(?Tenant $tenant = null): User
    {
        $tenant ??= Tenant::create(['uid' => (string) Str::ulid(), 'code' => Str::random(8), 'name' => 'Example tenant', 'is_active' => true]);
        $company = Company::create(['uid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'legal_name' => 'Example Agency', 'display_name' => 'Example Agency', 'base_currency_code' => 'PKR', 'default_timezone' => 'UTC', 'is_active' => true, 'is_paid' => true]);
        $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => 'admin'], ['uid' => (string) Str::ulid(), 'name' => 'Admin', 'is_system' => false]);

        return User::create(['uid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'role_id' => $role->id, 'name' => 'Example user', 'email' => Str::ulid().'@example.com', 'password' => 'password123', 'email_verified_at' => now(), 'is_active' => true]);
    }

    private function customer(User $user, string $name): Customer
    {
        return Customer::create(['uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id, 'company_id' => $user->company_id, 'name' => $name, 'type' => 'B2C', 'currency_code' => 'PKR']);
    }

    private function order(User $user, Customer $customer, string $name): Order
    {
        $order = Order::create(['uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id, 'company_id' => $user->company_id, 'customer_id' => $customer->id, 'order_number' => $name, 'status' => 'order', 'currency_code' => 'PKR', 'total_amount' => 100, 'created_by_user_id' => $user->id, 'updated_by_user_id' => $user->id, 'share_token' => Str::random(64)]);
        OrderItem::create(['uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id, 'order_id' => $order->id, 'description' => $name, 'quantity' => 1, 'unit_price' => 100, 'total_price' => 100]);

        return $order;
    }
}
