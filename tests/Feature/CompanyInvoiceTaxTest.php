<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderVendorCost;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\InvoiceService;
use App\Services\InvoiceTaxService;
use App\Services\PaymentService;
use App\Services\ReportService;
use App\Services\StatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyInvoiceTaxTest extends TestCase
{
    use RefreshDatabase;

    private function taxableContext(string $pricing = 'exclusive', string $currency = 'PKR'): array
    {
        $ctx = $this->seedTenantContext();
        $ctx['company']->update(['tax_settings' => [...InvoiceTaxService::defaults(), 'enabled' => true, 'name' => 'Sales Tax', 'registration_number' => 'REG-123', 'rate' => '10', 'pricing' => $pricing, 'categories' => ['flights']]]);
        $ctx['order']->update(['currency_code' => $currency]);
        $ctx['order']->items()->update(['gds_data' => json_encode(['type' => 'pricing'])]);

        return $ctx;
    }

    public function test_disabled_and_historical_invoices_keep_legacy_totals(): void
    {
        $ctx = $this->seedTenantContext();
        $invoice = app(InvoiceService::class)->createFromOrder($ctx['order']);
        $this->assertNull($invoice->tax_settings);
        $this->assertSame('1000.0000', $invoice->total_amount);
        $ctx['company']->update(['tax_settings' => [...InvoiceTaxService::defaults(), 'enabled' => true, 'rate' => '50', 'categories' => ['flights']]]);
        app(InvoiceService::class)->applyDiscount($invoice, 100);
        $invoice->refresh();
        $this->assertSame('900.0000', $invoice->total_amount);
        $this->assertSame('0.0000', $invoice->tax_amount);
        $this->assertNull($invoice->tax_settings);
        $refund = $this->postJson('/api/v1/orders/'.$ctx['order']->uid.'/refund-request')->assertCreated();
        $order = Order::where('uid', $refund->json('order.uid'))->firstOrFail();
        $refundInvoice = app(InvoiceService::class)->createFromOrder($order);
        $this->assertSame('-900.0000', $refundInvoice->total_amount);
        $this->assertNull($refundInvoice->tax_settings);
        $this->assertSame('0.0000', $refundInvoice->tax_amount);
    }

    public function test_exclusive_tax_and_saved_settings_are_independent_of_currency_and_company_changes(): void
    {
        foreach (['PKR', 'USD', 'SAR', 'AED'] as $currency) {
            $ctx = $this->taxableContext(currency: $currency);
            $invoice = app(InvoiceService::class)->createFromOrder($ctx['order']);
            $this->assertSame('1100.0000', $invoice->total_amount);
            $this->assertSame('100.0000', $invoice->tax_amount);
            $ctx['company']->update(['tax_settings' => InvoiceTaxService::defaults()]);
            app(InvoiceService::class)->applyDiscount($invoice, 100);
            $invoice->refresh();
            $this->assertSame('990.0000', $invoice->total_amount);
            $this->assertSame('90.0000', $invoice->tax_amount);
            $this->assertSame('Sales Tax', $invoice->tax_settings['name']);
            $this->assertSame('REG-123', $invoice->tax_settings['registration_number']);
        }
    }

    public function test_categories_and_inclusive_prices_allocate_discounts_proportionally(): void
    {
        $ctx = $this->taxableContext('inclusive');
        OrderItem::create(['uid' => (string) Str::ulid(), 'tenant_id' => $ctx['tenant']->id, 'order_id' => $ctx['order']->id, 'description' => 'Hotel', 'quantity' => 1, 'unit_price' => 1000, 'total_price' => 1000, 'gds_data' => ['type' => 'hotel']]);
        $invoice = app(InvoiceService::class)->createFromOrder($ctx['order']);
        $this->assertSame('2000.0000', $invoice->total_amount);
        $this->assertSame('90.9100', $invoice->tax_amount);
        $discount = app(InvoiceService::class)->createDiscount($invoice, 'percentage', 10);
        $invoice->refresh();
        $this->assertSame('1800.0000', $invoice->total_amount);
        $this->assertSame('81.8200', $invoice->tax_amount);
        app(InvoiceService::class)->updateDiscount($discount, 'amount', 400);
        $this->assertSame('72.7300', $invoice->fresh()->tax_amount);
        app(InvoiceService::class)->deleteDiscount($discount);
        $this->assertSame('90.9100', $invoice->fresh()->tax_amount);
    }

    public function test_payment_settles_gross_balance_and_statement_uses_saved_total(): void
    {
        $ctx = $this->taxableContext();
        $invoice = app(InvoiceService::class)->createFromOrder($ctx['order']);
        app(PaymentService::class)->recordCustomerReceipt($invoice, 1100);
        $invoice->refresh();
        $this->assertSame('0.0000', $invoice->outstanding_amount);
        $this->assertSame('100.0000', $invoice->tax_amount);
        $this->assertSame('1100.0000', $invoice->total_amount);
        $statement = app(StatementService::class)->customerStatement($ctx['tenant']->id, $ctx['company']->id, $ctx['customer']->id);
        $this->assertEquals(1100, $statement['summary']['total_amount']);
        $this->assertEquals(0, $statement['summary']['total_outstanding']);
        $this->expectException(ValidationException::class);
        app(InvoiceService::class)->applyDiscount($invoice, 1);
    }

    public function test_refund_preserves_discount_and_tax_after_company_changes(): void
    {
        foreach (['exclusive', 'inclusive'] as $pricing) {
            $ctx = $this->taxableContext($pricing);
            $invoice = app(InvoiceService::class)->createFromOrder($ctx['order']);
            app(InvoiceService::class)->applyDiscount($invoice, 100);
            $invoice->refresh();
            $ctx['company']->update(['tax_settings' => [...InvoiceTaxService::defaults(), 'enabled' => true, 'rate' => '70', 'categories' => ['hotels']]]);
            $response = $this->postJson('/api/v1/orders/'.$ctx['order']->uid.'/refund-request')->assertCreated();
            $refund = Order::where('uid', $response->json('order.uid'))->firstOrFail();
            $this->assertEquals(-(float) $invoice->total_amount, (float) $refund->total_amount);
            $this->assertEquals(-(float) $invoice->tax_amount, $refund->meta['invoice_tax_amount']);
            $refund->items()->update(['unit_price' => -500, 'total_price' => -500]);
            app(InvoiceTaxService::class)->syncRefund($refund);
            $this->assertEquals($pricing === 'exclusive' ? -495 : -450, (float) $refund->total_amount);
            $refundInvoice = app(InvoiceService::class)->createFromOrder($refund);
            $this->assertSame($refund->total_amount, $refundInvoice->total_amount);
            $this->assertEquals($refund->meta['invoice_tax_amount'], (float) $refundInvoice->tax_amount);
            $this->assertSame('10', $refundInvoice->tax_settings['rate']);
            $this->getJson('/api/v1/invoices/'.$refundInvoice->uid)->assertOk()->assertJsonPath('tax_settings.name', 'Sales Tax');
        }
    }

    public function test_shared_invoice_exposes_saved_tax_and_no_private_settings_override(): void
    {
        $ctx = $this->taxableContext();
        $data = $this->postJson('/api/v1/invoices/create-from-order', ['order_id' => $ctx['order']->id, 'tax_settings' => ['rate' => 0], 'tax_amount' => 0])->assertCreated()->json('invoice');
        $invoice = Invoice::where('uid', $data['uid'])->firstOrFail();
        $invoice->update(['share_token' => 'tax-share-token']);
        $ctx['company']->update(['tax_settings' => InvoiceTaxService::defaults()]);
        $this->getJson('/api/v1/shared-invoices/tax-share-token')->assertOk()
            ->assertJsonPath('tax_settings.registration_number', 'REG-123')
            ->assertJsonPath('tax_amount', '100.0000')
            ->assertJsonPath('total_amount', '1100.0000');
    }

    public function test_profit_excludes_tax_inclusive_and_exclusive(): void
    {
        foreach (['exclusive', 'inclusive'] as $pricing) {
            $ctx = $this->taxableContext($pricing);
            $invoice = app(InvoiceService::class)->createFromOrder($ctx['order']);
            OrderVendorCost::create(['tenant_id' => $ctx['tenant']->id, 'order_id' => $ctx['order']->id, 'vendor_id' => $ctx['vendor']->id, 'service_type' => 'flight', 'service_index' => 0, 'amount' => 200]);
            $report = app(ReportService::class)->profitReport($ctx['tenant']->id, $ctx['company']->id, now()->toDateString(), now()->toDateString());
            $expected = (float) $invoice->total_amount - (float) $invoice->tax_amount - 200;
            $this->assertEqualsWithDelta($expected, $report['summary']['by_currency'][0]['profit'], 0.0001);
            $performance = app(ReportService::class)->performanceReport($ctx['tenant']->id, $ctx['company']->id, now()->toDateString(), now()->toDateString());
            $this->assertEqualsWithDelta($expected, $performance['summary']['total_profit_loss'], 0.0001);
            $dashboard = app(ReportService::class)->dashboardUpcoming($ctx['tenant']->id, $ctx['company']->id, 30, true);
            $this->assertEqualsWithDelta($expected, $dashboard['finance']['summary']['profit'], 0.01);
            $shares = $this->getJson('/api/v1/profit-shares')->assertOk();
            $this->assertEqualsWithDelta($expected, $shares->json('source_profits.0.profit'), 0.0001);
        }
    }

    public function test_only_owner_can_update_settings_and_invalid_settings_are_rejected(): void
    {
        $ctx = $this->seedTenantContext();
        $payload = ['legal_name' => 'Tax Company', 'display_name' => 'Tax Company', 'default_timezone' => 'UTC', 'tax_settings' => [...InvoiceTaxService::defaults(), 'enabled' => true, 'rate' => 10, 'categories' => ['flights']]];
        $this->postJson('/api/v1/company-profile', $payload)->assertForbidden();
        $ctx['user']->role->update(['code' => 'owner']);
        Sanctum::actingAs($ctx['user']->fresh());
        $this->postJson('/api/v1/company-profile', $payload)->assertOk()->assertJsonPath('company.tax_settings.enabled', true);
        $payload['tax_settings']['categories'] = ['invalid'];
        $this->postJson('/api/v1/company-profile', $payload)->assertUnprocessable();
        $payload['tax_settings']['categories'] = [];
        $this->postJson('/api/v1/company-profile', $payload)->assertUnprocessable();
        $payload['tax_settings']['enabled'] = false;
        $payload['tax_settings'] = json_encode($payload['tax_settings']);
        $this->post('/api/v1/company-profile', $payload, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('company.tax_settings.enabled', false);
    }

    public function test_partial_refund_edit_and_allocations_use_gross_saved_balance(): void
    {
        $ctx = $this->taxableContext();
        $invoice = app(InvoiceService::class)->createFromOrder($ctx['order']);
        app(InvoiceService::class)->applyDiscount($invoice, 100);
        $uid = $this->postJson('/api/v1/orders/'.$ctx['order']->uid.'/refund-request')->assertCreated()->json('order.uid');
        $payload = ['customer_id' => $ctx['customer']->id, 'status' => 'refund_request', 'currency_code' => 'PKR',
            'voucher' => ['active_sections' => ['flights'], 'pricing' => [['pax_name' => 'Traveler', 'flight_sales' => -500]]]];
        $this->patchJson('/api/v1/orders/'.$uid, $payload)->assertOk()->assertJsonPath('order.total_amount', '-495.0000');
        $refund = Order::where('uid', $uid)->firstOrFail();
        $this->postJson('/api/v1/payments/refund-allocations/customer-payment', [
            'customer_id' => $ctx['customer']->id, 'amount' => 495, 'payment_method' => 'cash',
            'allocations' => [['order_id' => $refund->id, 'amount' => 495]],
        ])->assertSuccessful();
        $this->assertEquals(-45, $refund->meta['invoice_tax_amount']);
        $payload['voucher']['pricing'][0]['flight_sales'] = -100;
        $this->patchJson('/api/v1/orders/'.$uid, $payload)->assertUnprocessable();
        $this->assertSame('-495.0000', $refund->fresh()->total_amount);
        $this->getJson('/api/v1/invoices/'.$uid)->assertOk()->assertJsonPath('tax_amount', -45);
        $this->postJson('/api/v1/invoices/'.$invoice->uid.'/discounts', ['discount_type' => 'amount', 'amount' => 10])->assertUnprocessable();
    }

    public function test_refund_cannot_change_original_currency_or_increase_category_amounts(): void
    {
        $ctx = $this->taxableContext();
        app(InvoiceService::class)->createFromOrder($ctx['order']);
        $uid = $this->postJson('/api/v1/orders/'.$ctx['order']->uid.'/refund-request')->assertCreated()->json('order.uid');
        $payload = ['customer_id' => $ctx['customer']->id, 'status' => 'refund_request', 'currency_code' => 'USD'];
        $this->patchJson('/api/v1/orders/'.$uid, $payload)->assertUnprocessable();
        $payload['currency_code'] = 'PKR';
        $payload['voucher'] = ['active_sections' => ['flights'], 'pricing' => [['pax_name' => 'Traveler', 'flight_sales' => -1100]]];
        $this->patchJson('/api/v1/orders/'.$uid, $payload)->assertUnprocessable();
        $this->assertSame('-1100.0000', Order::where('uid', $uid)->firstOrFail()->total_amount);
    }

    public function test_zero_rate_and_unselected_services_remain_untaxed(): void
    {
        $ctx = $this->taxableContext();
        $settings = $ctx['company']->tax_settings;
        $settings['categories'] = ['hotels'];
        $ctx['company']->update(['tax_settings' => $settings]);
        $invoice = app(InvoiceService::class)->createFromOrder($ctx['order']);
        $this->assertSame('0.0000', $invoice->tax_amount);
        $this->assertSame('1000.0000', $invoice->total_amount);
        $ctx = $this->taxableContext();
        $settings = $ctx['company']->tax_settings;
        $settings['rate'] = '0';
        $ctx['company']->update(['tax_settings' => $settings]);
        $invoice = app(InvoiceService::class)->createFromOrder($ctx['order']);
        $this->assertSame('0.0000', $invoice->tax_amount);
        $this->assertSame('1000.0000', $invoice->total_amount);
    }

    public function test_currency_rounding_precision_is_snapshotted(): void
    {
        DB::table('currencies')->insert(['code' => 'KWD', 'name' => 'Kuwaiti Dinar', 'decimal_places' => 3, 'is_active' => true]);
        $ctx = $this->taxableContext('inclusive', 'KWD');
        $invoice = app(InvoiceService::class)->createFromOrder($ctx['order']);
        $this->assertSame('90.9090', $invoice->tax_amount);
        DB::table('currencies')->where('code', 'KWD')->update(['decimal_places' => 2]);
        app(InvoiceService::class)->applyDiscount($invoice, 100);
        $this->assertSame('81.8180', $invoice->fresh()->tax_amount);
        $this->assertSame(3, $invoice->fresh()->tax_settings['precision']);
    }

    public function test_tax_report_uses_snapshots_and_separates_currencies(): void
    {
        $ctx = $this->taxableContext();
        $invoice = app(InvoiceService::class)->createFromOrder($ctx['order']);
        app(InvoiceService::class)->applyDiscount($invoice, 100);
        $otherOrder = Order::create([...$ctx['order']->only(['tenant_id', 'company_id', 'customer_id', 'vendor_id', 'created_by_user_id', 'updated_by_user_id']),
            'uid' => (string) Str::ulid(), 'order_number' => 'TAX-USD', 'status' => 'order', 'currency_code' => 'USD', 'total_amount' => 1000]);
        OrderItem::create(['uid' => (string) Str::ulid(), 'tenant_id' => $ctx['tenant']->id, 'order_id' => $otherOrder->id,
            'description' => 'Hotel', 'quantity' => 1, 'unit_price' => 1000, 'total_price' => 1000, 'gds_data' => ['type' => 'hotel']]);
        $ctx['company']->update(['tax_settings' => [...InvoiceTaxService::defaults(), 'enabled' => true, 'name' => 'VAT', 'rate' => '20', 'pricing' => 'inclusive', 'categories' => ['hotels']]]);
        app(InvoiceService::class)->createFromOrder($otherOrder);
        $ctx['company']->update(['tax_settings' => InvoiceTaxService::defaults()]);
        $url = '/api/v1/reports/tax?from_date='.now()->toDateString().'&to_date='.now()->toDateString();
        $report = $this->getJson($url)->assertOk()->assertJsonPath('summary.total_records', 2);
        $this->assertEquals(90, $report->json('summary.by_currency.0.net_tax'));
        $this->assertEquals(166.67, $report->json('summary.by_currency.1.net_tax'));
        $this->assertSame('PKR', $report->json('summary.by_currency.0.currency_code'));
        $this->getJson($url.'&currency_code=PKR&search=REG-123&pricing=exclusive')->assertOk()
            ->assertJsonPath('summary.total_records', 1)->assertJsonPath('data.0.tax_label', 'Sales Tax');
        $this->getJson($url.'&pricing=inclusive')->assertOk()->assertJsonPath('summary.total_records', 1);
        $this->getJson($url.'&search=missing')->assertOk()->assertJsonPath('summary.total_records', 0);
    }

    public function test_tax_report_refunds_are_confirmed_and_never_double_counted(): void
    {
        $ctx = $this->taxableContext();
        app(InvoiceService::class)->createFromOrder($ctx['order']);
        $uid = $this->postJson('/api/v1/orders/'.$ctx['order']->uid.'/refund-request')->assertCreated()->json('order.uid');
        $refund = Order::where('uid', $uid)->firstOrFail();
        $url = '/api/v1/reports/tax?from_date='.now()->toDateString().'&to_date='.now()->toDateString();
        $this->getJson($url)->assertOk()->assertJsonPath('summary.refund_count', 0);
        $refund->update(['status' => 'partial_refund']);
        $report = $this->getJson($url)->assertOk()->assertJsonPath('summary.total_records', 2)->assertJsonPath('summary.refund_count', 1);
        $this->assertEquals(100, $report->json('summary.by_currency.0.tax_charged'));
        $this->assertEquals(100, $report->json('summary.by_currency.0.tax_reversed'));
        $this->assertEquals(0, $report->json('summary.by_currency.0.net_tax'));
        $this->getJson($url.'&type=refund')->assertOk()->assertJsonPath('summary.total_records', 1);
        app(InvoiceService::class)->createFromOrder($refund);
        $this->getJson($url)->assertOk()->assertJsonPath('summary.total_records', 2)->assertJsonPath('summary.refund_count', 1);
    }

    public function test_shared_reference_search_finds_nested_voucher_identifiers(): void
    {
        $ctx = $this->taxableContext();
        $ctx['order']->update(['status' => 'order', 'meta' => [
            'flights' => [['gds_pnr' => 'HC0DRX', 'pnr' => 'F5P7X9 (TK)']],
            'pricing' => [['flight_ticket_no' => '2351234567890']],
            'passengers' => [['ticket_no' => '2359876543210']],
            'visa' => [['visa_no' => 'VISA-763']],
            'hotels' => [['hcn' => 'HCN-854']],
            'transfers' => [['tn' => 'TN-925']],
        ]]);
        app(InvoiceService::class)->createFromOrder($ctx['order']);
        $ctx['order']->update(['status' => 'order']);
        foreach (['hc0drx', 'F5P7X9', '2351234567890', '2359876543210', 'VISA-763', 'HCN-854', 'TN-925'] as $reference) {
            foreach (['orders', 'invoices'] as $resource) {
                $this->getJson('/api/v1/'.$resource.'?search='.urlencode($reference))->assertOk()->assertJsonPath('total', 1);
            }
        }
        $this->getJson('/api/v1/orders?search=NOT-A-REFERENCE')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/invoices?search=NOT-A-REFERENCE')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_paged_tax_report_keeps_totals_and_validates_unchanged_response(): void
    {
        $ctx = $this->taxableContext();
        $ctx['order']->update(['booking_reference' => 'PNR-TEST-42', 'status' => 'order']);
        $this->getJson('/api/v1/orders?search=PNR-TEST-42')->assertOk()->assertJsonPath('total', 1);
        app(InvoiceService::class)->createFromOrder($ctx['order']);
        $this->getJson('/api/v1/invoices?search=PNR-TEST-42')->assertOk()->assertJsonPath('total', 1);
        $url = '/api/v1/reports/tax?from_date='.now()->toDateString().'&to_date='.now()->toDateString().'&page=2&per_page=1';
        $response = $this->getJson($url)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.total_records', 1);
        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->getJson($url, ['If-None-Match' => $etag])->assertStatus(304)->assertContent('');
    }

    public function test_tax_report_excludes_external_cancelled_and_out_of_period_invoices(): void
    {
        $ctx = $this->taxableContext();
        $invoice = app(InvoiceService::class)->createFromOrder($ctx['order']);
        $url = '/api/v1/reports/tax?from_date='.now()->toDateString().'&to_date='.now()->toDateString();
        foreach (['draft', 'void', 'cancel'] as $status) {
            $invoice->update(['status' => $status]);
            $this->getJson($url)->assertOk()->assertJsonPath('summary.total_records', 0);
        }
        $invoice->update(['status' => 'issued', 'invoice_date' => now()->subDay()->toDateString()]);
        $this->getJson($url)->assertOk()->assertJsonPath('summary.total_records', 0);
        $invoice->update(['invoice_date' => now()->toDateString(), 'tax_settings' => null]);
        $this->getJson($url)->assertOk()->assertJsonPath('summary.total_records', 0);
    }

    public function test_tax_report_is_company_scoped_and_role_protected(): void
    {
        $url = '/api/v1/reports/tax?from_date='.now()->toDateString().'&to_date='.now()->toDateString();
        $this->getJson($url)->assertUnauthorized();
        $ctx = $this->taxableContext();
        app(InvoiceService::class)->createFromOrder($ctx['order']);
        $other = $this->taxableContext();
        app(InvoiceService::class)->createFromOrder($other['order']);
        Sanctum::actingAs($ctx['user']->fresh());
        $this->getJson($url)->assertOk()->assertJsonPath('summary.total_records', 1);
        $other['company']->update(['tenant_id' => $ctx['tenant']->id]);
        Invoice::where('company_id', $other['company']->id)->update(['tenant_id' => $ctx['tenant']->id]);
        $this->getJson($url)->assertOk()->assertJsonPath('summary.total_records', 1);
        foreach (['owner', 'accounts', 'sales'] as $role) {
            $ctx['user']->role->update(['code' => $role]);
            Sanctum::actingAs($ctx['user']->fresh());
            $this->getJson($url)->assertStatus($role === 'sales' ? 403 : 200);
        }
        $ctx['user']->role->update(['code' => 'admin']);
        Sanctum::actingAs($ctx['user']->fresh());
        $this->getJson('/api/v1/reports/tax?from_date=2026-09-15&to_date=2026-09-01')->assertUnprocessable();
        $this->getJson($url.'&type=invalid')->assertUnprocessable();
    }

    private function seedTenantContext(): array
    {
        $tenant = Tenant::create([
            'uid' => (string) Str::ulid(),
            'code' => 'api'.fake()->unique()->numerify('###'),
            'name' => 'API Test Tenant',
            'is_active' => true,
        ]);

        $company = Company::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'legal_name' => 'API Test Company Legal',
            'display_name' => 'API Test Company',
            'email' => 'company-'.fake()->unique()->safeEmail(),
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
            'email' => 'user-'.fake()->unique()->safeEmail(),
            'password' => 'password123',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => 'B2C',
            'name' => 'Test Customer',
            'email' => 'customer-'.fake()->unique()->safeEmail(),
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
            'email' => 'vendor-'.fake()->unique()->safeEmail(),
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
            'order_number' => 'ORD-'.fake()->unique()->numerify('######'),
            'booking_reference' => 'PNR'.fake()->numerify('#####'),
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
