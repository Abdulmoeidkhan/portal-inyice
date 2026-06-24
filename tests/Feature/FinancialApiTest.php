<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Receipt;
use App\Models\VendorPaymentAllocation;
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

        $payment = $this->postJson('/api/v1/receipts/customer/record', [
            'invoice_uid' => $invoiceUid,
            'amount' => 400,
            'payment_method' => 'bank_transfer',
            'account_id' => $bankId,
            'reference_number' => 'PAY-001',
        ]);

        $payment->assertCreated();
        $payment->assertJsonPath('success', true);
        $payment->assertJsonPath('invoice.status', 'partial_paid');

        $vendorPayment = $this->postJson('/api/v1/payments/vendor', [
            'vendor_id' => $ctx['vendor']->id,
            'amount' => 400,
            'payment_method' => 'cash',
            'payment_date' => '2026-06-18',
            'narration' => 'Supplier settlement',
        ]);
        $vendorPayment->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('outstanding_balance', 600);

        $report = $this->getJson('/api/v1/reports/payments?from_date=2020-01-01&to_date=2030-12-31');
        $report->assertOk()
            ->assertJsonPath('summary.total_records', 1)
            ->assertJsonPath('summary.customer_records', 0)
            ->assertJsonPath('summary.vendor_records', 1)
            ->assertJsonPath('summary.by_currency.0.currency_code', 'PKR')
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/reports/receipts?from_date=2020-01-01&to_date=2030-12-31&counterparty_type=customer')
            ->assertOk()
            ->assertJsonPath('summary.total_records', 1)
            ->assertJsonPath('data.0.direction', 'money_in')
            ->assertJsonPath('data.0.counterparty_name', 'Test Customer');

        $this->getJson('/api/v1/payments/vendor')
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->getJson('/api/v1/statements/vendor/' . $ctx['vendor']->id)
            ->assertOk()
            ->assertJsonPath('summary.period_payables', 1000)
            ->assertJsonPath('summary.period_paid', 400)
            ->assertJsonPath('summary.outstanding_balance', 600);

        $advance = $this->postJson('/api/v1/receipts/customer/advance', [
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

    public function test_bulk_payment_allocates_one_receipt_across_same_customer_invoices(): void
    {
        $ctx = $this->seedTenantContext();
        $secondOrder = $ctx['order']->replicate();
        $secondOrder->uid = (string) Str::ulid();
        $secondOrder->order_number = 'ORD-' . fake()->unique()->numerify('######');
        $secondOrder->booking_reference = 'PNR' . fake()->numerify('#####');
        $secondOrder->save();

        foreach ($ctx['order']->items as $item) {
            $secondItem = $item->replicate();
            $secondItem->uid = (string) Str::ulid();
            $secondItem->order_id = $secondOrder->id;
            $secondItem->save();
        }

        $firstInvoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated()->json('invoice');
        $secondInvoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $secondOrder->id,
        ])->assertCreated()->json('invoice');

        $response = $this->postJson('/api/v1/receipts/customer/record-bulk', [
            'allocations' => [
                ['invoice_uid' => $firstInvoice['uid'], 'amount' => 500],
                ['invoice_uid' => $secondInvoice['uid'], 'amount' => 1000],
            ],
            'amount' => 1500,
            'payment_method' => 'cash',
            'payment_date' => '2026-06-15',
            'narration' => 'Combined customer payment',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'allocations')
            ->assertJsonPath('receipt.amount', '1500.0000');

        $this->assertDatabaseHas('invoices', [
            'uid' => $firstInvoice['uid'],
            'outstanding_amount' => 500,
            'status' => 'partial_paid',
        ]);
        $this->assertDatabaseHas('invoices', [
            'uid' => $secondInvoice['uid'],
            'outstanding_amount' => 0,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('receipts', [
            'description' => 'Combined customer payment',
            'amount' => 1500,
        ]);
        $this->assertSame(
            '2026-06-15',
            Receipt::where('description', 'Combined customer payment')->firstOrFail()->receipt_date->toDateString()
        );
    }

    public function test_bulk_vendor_payment_allocates_one_payment_across_payable_orders(): void
    {
        $ctx = $this->seedTenantContext();
        $secondOrder = $ctx['order']->replicate();
        $secondOrder->uid = (string) Str::ulid();
        $secondOrder->order_number = 'ORD-' . fake()->unique()->numerify('######');
        $secondOrder->booking_reference = 'PNR' . fake()->numerify('#####');
        $secondOrder->save();

        $payables = $this->getJson('/api/v1/payments/vendor/' . $ctx['vendor']->id . '/payables')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->json('data');

        $response = $this->postJson('/api/v1/payments/vendor', [
            'vendor_id' => $ctx['vendor']->id,
            'amount' => 1500,
            'payment_method' => 'cash',
            'payment_date' => '2026-06-19',
            'reference_number' => 'BULK-VENDOR-001',
            'allocations' => [
                ['order_id' => $payables[0]['id'], 'amount' => 1000],
                ['order_id' => $payables[1]['id'], 'amount' => 500],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'payment.allocations');
        $this->assertSame(2, VendorPaymentAllocation::count());

        $this->getJson('/api/v1/payments/vendor/' . $ctx['vendor']->id . '/payables')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('outstanding_total', 500);
    }

    public function test_payments_can_be_reallocated_deleted_refunded_and_invoices_shared(): void
    {
        $ctx = $this->seedTenantContext();
        $invoice = $this->postJson('/api/v1/invoices/create-from-order', ['order_id' => $ctx['order']->id])
            ->assertCreated()->json('invoice');

        $this->postJson('/api/v1/receipts/customer/record', [
            'invoice_uid' => $invoice['uid'], 'amount' => 400, 'payment_method' => 'cash',
        ])->assertCreated();
        $receipt = $this->getJson('/api/v1/receipts/customer')->assertOk()->json('data.0');

        $this->patchJson('/api/v1/receipts/customer/' . $receipt['uid'], [
            'date' => '2026-06-20', 'payment_method' => 'cash',
            'allocations' => [['invoice_id' => $invoice['id'], 'amount' => 250]],
        ])->assertOk()->assertJsonPath('receipt.amount', '250.0000');
        $this->assertDatabaseHas('invoices', ['id' => $invoice['id'], 'outstanding_amount' => 750]);

        $this->postJson('/api/v1/payments/customer/refund', [
            'invoice_uid' => $invoice['uid'], 'amount' => 100, 'reason' => 'Partial service refund',
        ])->assertCreated();
        $this->postJson('/api/v1/payments/customer/refund', [
            'invoice_uid' => $invoice['uid'], 'amount' => 151,
        ])->assertUnprocessable()->assertJsonPath('error', 'Refund cannot exceed the refundable paid amount.');

        $share = $this->postJson('/api/v1/invoices/' . $invoice['uid'] . '/share')->assertOk();
        $this->getJson('/api/v1/shared-invoices/' . $share->json('share_token'))
            ->assertOk()->assertJsonPath('invoice_number', $invoice['invoice_number']);

        $this->deleteJson('/api/v1/receipts/customer/' . $receipt['uid'])
            ->assertUnprocessable()->assertJsonPath('error', 'A refunded receipt cannot be deleted.');
        $this->assertDatabaseHas('receipts', ['uid' => $receipt['uid']]);
        $this->deleteJson('/api/v1/receipts/customer/' . $receipt['uid'] . '-missing')->assertNotFound();

        // A separate unrefunded receipt can be safely deleted and its allocation restored.
        $secondOrder = $ctx['order']->replicate();
        $secondOrder->uid = (string) Str::ulid();
        $secondOrder->order_number = 'ORD-' . fake()->unique()->numerify('######');
        $secondOrder->booking_reference = 'PNR' . fake()->numerify('#####');
        $secondOrder->save();
        foreach ($ctx['order']->items as $item) {
            $copy = $item->replicate(); $copy->uid = (string) Str::ulid(); $copy->order_id = $secondOrder->id; $copy->save();
        }
        $secondInvoice = $this->postJson('/api/v1/invoices/create-from-order', ['order_id' => $secondOrder->id])->assertCreated()->json('invoice');
        $this->postJson('/api/v1/receipts/customer/record', ['invoice_uid' => $secondInvoice['uid'], 'amount' => 100, 'payment_method' => 'cash'])->assertCreated();
        $secondReceipt = $this->getJson('/api/v1/receipts/customer')->assertOk()->json('data.0');
        $this->deleteJson('/api/v1/receipts/customer/' . $secondReceipt['uid'])->assertOk();
        $this->assertDatabaseMissing('receipts', ['uid' => $secondReceipt['uid']]);
        $this->assertDatabaseHas('invoices', ['id' => $secondInvoice['id'], 'outstanding_amount' => 1000]);

        $vendorPayment = $this->postJson('/api/v1/payments/vendor', [
            'vendor_id' => $ctx['vendor']->id, 'amount' => 400, 'payment_method' => 'cash',
            'allocations' => [['order_id' => $ctx['order']->id, 'amount' => 400]],
        ])->assertCreated()->json('payment');
        $this->patchJson('/api/v1/payments/vendor/payment/' . $vendorPayment['uid'], [
            'date' => '2026-06-20', 'payment_method' => 'cash',
            'allocations' => [['order_id' => $ctx['order']->id, 'amount' => 300]],
        ])->assertOk()->assertJsonPath('payment.amount', '300.0000');
        $this->deleteJson('/api/v1/payments/vendor/payment/' . $vendorPayment['uid'])->assertOk();
        $this->assertDatabaseMissing('payments', ['uid' => $vendorPayment['uid']]);
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

        $this->postJson('/api/v1/receipts/customer/record', [
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

    public function test_customer_payments_and_vendor_receipts_follow_cash_direction(): void
    {
        $ctx = $this->seedTenantContext();

        $customerPayment = $this->postJson('/api/v1/payments/customer', [
            'customer_id' => $ctx['customer']->id, 'amount' => 125,
            'payment_method' => 'cash', 'payment_date' => '2026-06-20',
            'description' => 'Customer goodwill payment',
        ])->assertCreated()->assertJsonPath('payment.customer.name', 'Test Customer')->json('payment');

        $vendorReceipt = $this->postJson('/api/v1/receipts/vendor', [
            'vendor_id' => $ctx['vendor']->id, 'amount' => 75,
            'payment_method' => 'bank_transfer', 'receipt_date' => '2026-06-20',
            'description' => 'Vendor rebate received',
        ])->assertCreated()->assertJsonPath('receipt.vendor.name', 'Test Vendor')->json('receipt');

        $this->patchJson('/api/v1/payments/customer/' . $customerPayment['uid'], [
            'date' => '2026-06-20', 'amount' => 130, 'payment_method' => 'cash', 'description' => 'Updated customer payment',
        ])->assertOk()->assertJsonPath('payment.amount', '130.0000');
        $this->patchJson('/api/v1/receipts/vendor/' . $vendorReceipt['uid'], [
            'date' => '2026-06-20', 'amount' => 80, 'payment_method' => 'bank_transfer', 'description' => 'Updated vendor receipt',
        ])->assertOk()->assertJsonPath('receipt.amount', '80.0000');

        $this->getJson('/api/v1/reports/payments?from_date=2026-06-01&to_date=2026-06-30')
            ->assertOk()->assertJsonPath('direction', 'payment')->assertJsonPath('summary.customer_records', 1)
            ->assertJsonPath('data.0.counterparty_name', 'Test Customer');
        $this->getJson('/api/v1/reports/payments?from_date=2026-06-01&to_date=2026-06-30&counterparty_type=customer&counterparty_id=' . $ctx['customer']->id)
            ->assertOk()->assertJsonPath('summary.total_records', 1)
            ->assertJsonPath('data.0.counterparty_name', 'Test Customer');
        $this->getJson('/api/v1/reports/receipts?from_date=2026-06-01&to_date=2026-06-30')
            ->assertOk()->assertJsonPath('direction', 'receipt')->assertJsonPath('summary.vendor_records', 1)
            ->assertJsonPath('data.0.counterparty_name', 'Test Vendor');

        $this->getJson('/api/v1/statements/customer/' . $ctx['customer']->id)
            ->assertOk()->assertJsonPath('transactions.0.type', 'payment');
        $this->getJson('/api/v1/statements/vendor/' . $ctx['vendor']->id)
            ->assertOk()->assertJsonPath('summary.outstanding_balance', 1080);

        $this->deleteJson('/api/v1/payments/customer/' . $customerPayment['uid'])->assertOk();
        $this->deleteJson('/api/v1/receipts/vendor/' . $vendorReceipt['uid'])->assertOk();
        $this->assertDatabaseMissing('payments', ['uid' => $customerPayment['uid']]);
        $this->assertDatabaseMissing('receipts', ['uid' => $vendorReceipt['uid']]);
    }

    public function test_voucher_order_fields_are_searchable_and_vendor_costs_are_related_per_service(): void
    {
        $ctx = $this->seedTenantContext();
        $secondVendor = Vendor::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $ctx['tenant']->id,
            'company_id' => $ctx['company']->id,
            'type' => 'B2B',
            'name' => 'Second Test Vendor',
            'currency_code' => 'PKR',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/orders/create-from-voucher', [
            'customer_id' => $ctx['customer']->id,
            'currency_code' => 'PKR',
            'status' => 'order',
            'notes' => 'Multi-vendor package',
            'voucher' => [
                'voucher_no' => 'VCH-SEARCH-991',
                'issue_date' => '2026-06-24',
                'package_type' => 'Full Package',
                'booking_reference' => 'ZXCV12',
                'gds_source' => 'sabre',
                'emergency_contact' => 'Emergency search value',
                'active_sections' => ['flights', 'visa'],
                'flights' => [[
                    'date' => '2026-07-01',
                    'departure' => '10:30',
                    'arrival' => '13:45',
                    'booking_class' => 'A',
                    'flight_no' => 'PK301',
                    'from' => 'LHE',
                    'to' => 'JED',
                ]],
                'passengers' => [
                    ['name' => 'First Passenger', 'ticket_no' => '1234567890'],
                    ['name' => 'Second Passenger', 'ticket_no' => '1234567891'],
                ],
                'pricing' => [
                    [
                        'pax_name' => 'First Passenger',
                        'vendor_id' => $ctx['vendor']->id,
                        'flight_ticket_no' => '1234567890',
                        'flight_cost' => 400,
                        'flight_profit' => 100,
                        'flight_sales' => 500,
                    ],
                    [
                        'pax_name' => 'Second Passenger',
                        'vendor_id' => $secondVendor->id,
                        'flight_ticket_no' => '1234567891',
                        'flight_cost' => 200,
                        'flight_profit' => 50,
                        'flight_sales' => 250,
                    ],
                ],
                'visa' => [[
                    'passenger_name' => 'Second Passenger',
                    'vendor_id' => $secondVendor->id,
                    'visa_vendor' => $secondVendor->name,
                    'amount' => 100,
                ]],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('order.voucher_no', 'VCH-SEARCH-991')
            ->assertJsonPath('order.package_type', 'Full Package')
            ->assertJsonPath('order.booking_reference', 'ZXCV12');

        $orderId = (int) $response->json('order.id');
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'voucher_no' => 'VCH-SEARCH-991',
            'issue_date' => '2026-06-24',
            'package_type' => 'Full Package',
        ]);
        $this->assertDatabaseHas('order_vendor_costs', [
            'order_id' => $orderId,
            'vendor_id' => $ctx['vendor']->id,
            'service_type' => 'flight',
            'amount' => 400,
        ]);
        $this->assertDatabaseHas('order_vendor_costs', [
            'order_id' => $orderId,
            'vendor_id' => $secondVendor->id,
            'service_type' => 'visa',
            'amount' => 100,
        ]);

        $this->getJson('/api/v1/orders?search=VCH-SEARCH-991')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $orderId);
        $this->getJson('/api/v1/orders?search=ZXCV12')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $orderId);
        $this->getJson('/api/v1/payments/vendor/' . $ctx['vendor']->id . '/payables')
            ->assertOk()
            ->assertJsonFragment(['id' => $orderId, 'net_amount' => 400]);
        $this->getJson('/api/v1/payments/vendor/' . $secondVendor->id . '/payables')
            ->assertOk()
            ->assertJsonPath('data.0.net_amount', 300);

        $this->postJson('/api/v1/payments/vendor', [
            'vendor_id' => $ctx['vendor']->id,
            'amount' => 400,
            'payment_method' => 'cash',
            'payment_date' => '2026-06-24',
            'allocations' => [
                ['order_id' => $orderId, 'amount' => 400],
            ],
        ])->assertCreated();

        $this->getJson('/api/v1/payments/vendor/' . $secondVendor->id . '/payables')
            ->assertOk()
            ->assertJsonPath('outstanding_total', 300);
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
