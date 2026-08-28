<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceDiscount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderVendorCost;
use App\Models\Payment;
use App\Models\ProfitShare;
use App\Models\Role;
use App\Models\Receipt;
use App\Models\Receiving;
use App\Models\RefundAllocation;
use App\Models\VendorPaymentAllocation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $list->assertJsonPath('data.0.can_void_invoice', true);

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
        $void->assertJsonPath('invoice.status', 'void')
            ->assertJsonPath('invoice.total_amount', '0.0000')
            ->assertJsonPath('invoice.outstanding_amount', '0.0000')
            ->assertJsonPath('invoice.lines.0.total_price', '0.0000');

        $notes = Invoice::where('uid', $invoiceUid)->value('notes');
        $this->assertStringContainsString('Previous totals:', $notes);
        $this->assertStringContainsString('- Revenue: PKR 1000.00', $notes);
        $this->assertStringContainsString('- Cost:', $notes);
        $this->assertStringContainsString('- Profit:', $notes);
    }

    public function test_invoice_with_refund_request_cannot_be_voided(): void
    {
        $ctx = $this->seedTenantContext();

        $invoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated()->json('invoice');

        OrderVendorCost::create([
            'tenant_id' => $ctx['tenant']->id,
            'order_id' => $ctx['order']->id,
            'vendor_id' => $ctx['vendor']->id,
            'service_type' => 'flight',
            'service_index' => 0,
            'amount' => 700,
        ]);

        $this->postJson('/api/v1/orders/' . $ctx['order']->uid . '/refund-request')
            ->assertCreated();

        $this->getJson('/api/v1/invoices')
            ->assertOk()
            ->assertJsonFragment([
                'uid' => $invoice['uid'],
                'can_void_invoice' => false,
            ]);

        $this->patchJson('/api/v1/invoices/' . $invoice['uid'] . '/void')
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Cannot void this invoice because a refund request or refund order already exists.');

        $this->assertDatabaseHas('invoices', [
            'uid' => $invoice['uid'],
            'total_amount' => 1000,
            'status' => 'issued',
        ]);
    }

    public function test_invoice_delete_requires_password_and_invoice_number_then_cancels_invoice(): void
    {
        $ctx = $this->seedTenantContext();

        $invoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated()->json('invoice');

        $this->patchJson('/api/v1/invoices/' . $invoice['uid'] . '/cancel', [
            'password' => 'wrong-password',
            'invoice_number' => $invoice['invoice_number'],
        ])->assertStatus(403);

        $this->patchJson('/api/v1/invoices/' . $invoice['uid'] . '/cancel', [
            'password' => 'password123',
            'invoice_number' => 'WRONG-INVOICE',
        ])->assertStatus(422);

        $this->patchJson('/api/v1/invoices/' . $invoice['uid'] . '/cancel', [
            'password' => 'password123',
            'invoice_number' => $invoice['invoice_number'],
        ])->assertOk()
            ->assertJsonPath('invoice.status', 'cancel');

        $this->assertDatabaseHas('invoices', [
            'uid' => $invoice['uid'],
            'status' => 'cancel',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $ctx['order']->id,
            'status' => 'cancel',
        ]);
        $this->assertStringContainsString(
            'Deleted from invoices tab',
            Invoice::where('uid', $invoice['uid'])->value('notes')
        );
        $this->getJson('/api/v1/invoices')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_paid_or_partial_paid_invoice_cannot_be_deleted_or_voided_until_payment_is_removed(): void
    {
        $ctx = $this->seedTenantContext();

        $partialInvoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated()->json('invoice');

        $this->postJson('/api/v1/receipts/customer/record', [
            'invoice_uid' => $partialInvoice['uid'],
            'amount' => 400,
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->getJson('/api/v1/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'partial_paid')
            ->assertJsonPath('data.0.can_void_invoice', false)
            ->assertJsonPath('data.0.can_delete_invoice', false);

        $this->patchJson('/api/v1/invoices/' . $partialInvoice['uid'] . '/void')
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Remove payments from this invoice before deleting or voiding it.');

        $this->patchJson('/api/v1/invoices/' . $partialInvoice['uid'] . '/cancel', [
            'password' => 'password123',
            'invoice_number' => $partialInvoice['invoice_number'],
        ])->assertUnprocessable()
            ->assertJsonPath('error', 'Remove payments from this invoice before deleting or voiding it.');

        $secondOrder = $ctx['order']->replicate();
        $secondOrder->uid = (string) Str::ulid();
        $secondOrder->order_number = 'ORD-' . fake()->unique()->numerify('######');
        $secondOrder->booking_reference = 'PNR' . fake()->numerify('#####');
        $secondOrder->save();
        foreach ($ctx['order']->items as $item) {
            $copy = $item->replicate();
            $copy->uid = (string) Str::ulid();
            $copy->order_id = $secondOrder->id;
            $copy->save();
        }

        $paidInvoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $secondOrder->id,
        ])->assertCreated()->json('invoice');

        $this->postJson('/api/v1/receipts/customer/record', [
            'invoice_uid' => $paidInvoice['uid'],
            'amount' => 1000,
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->patchJson('/api/v1/invoices/' . $paidInvoice['uid'] . '/void')
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Remove payments from this invoice before deleting or voiding it.');

        $this->patchJson('/api/v1/invoices/' . $paidInvoice['uid'] . '/cancel', [
            'password' => 'password123',
            'invoice_number' => $paidInvoice['invoice_number'],
        ])->assertUnprocessable()
            ->assertJsonPath('error', 'Remove payments from this invoice before deleting or voiding it.');

        $receipt = Receipt::whereHas('settlements', fn ($query) => $query->where('invoice_id', $partialInvoice['id']))->firstOrFail();
        $this->deleteJson('/api/v1/receipts/customer/' . $receipt->uid)->assertOk();

        $this->patchJson('/api/v1/invoices/' . $partialInvoice['uid'] . '/cancel', [
            'password' => 'password123',
            'invoice_number' => $partialInvoice['invoice_number'],
        ])->assertOk()
            ->assertJsonPath('invoice.status', 'cancel');
    }

    public function test_invoice_discounts_can_be_added_edited_deleted_and_affect_profit(): void
    {
        $ctx = $this->seedTenantContext();

        $invoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated()->json('invoice');

        $discount = $this->postJson('/api/v1/invoices/' . $invoice['uid'] . '/discounts', [
            'amount' => 100,
            'reason' => 'Seasonal adjustment',
        ])->assertCreated()
            ->assertJsonPath('invoice.total_amount', '900.0000')
            ->assertJsonPath('invoice.outstanding_amount', '900.0000')
            ->json('discount');

        $this->assertSame(1, InvoiceDiscount::count());
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => Invoice::where('uid', $invoice['uid'])->value('id'),
            'unit_price' => -100,
            'total_price' => -100,
        ]);

        $updated = $this->patchJson('/api/v1/invoices/' . $invoice['uid'] . '/discounts/' . $discount['uid'], [
            'discount_type' => 'percentage',
            'percentage' => 20,
            'reason' => 'Manager approval',
        ])->assertOk()
            ->assertJsonPath('discount.discount_type', 'percentage')
            ->assertJsonPath('discount.percentage', 20)
            ->assertJsonPath('discount.amount', 200)
            ->assertJsonPath('invoice.total_amount', '800.0000')
            ->assertJsonPath('invoice.outstanding_amount', '800.0000')
            ->json('discount');

        $this->assertDatabaseHas('invoice_discounts', [
            'uid' => $updated['uid'],
            'discount_type' => 'percentage',
            'percentage' => 20,
            'amount' => 200,
        ]);

        $this->getJson('/api/v1/reports/profit?from_date=2020-01-01&to_date=2030-12-31&group_by=customer&entity_id=' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonPath('data.0.revenue', 800)
            ->assertJsonPath('data.0.discount', 200)
            ->assertJsonPath('data.0.profit', 800);

        $this->getJson('/api/v1/reports/discounts?from_date=2020-01-01&to_date=2030-12-31&discount_type=percentage&customer_id=' . $ctx['customer']->id . '&search=Manager')
            ->assertOk()
            ->assertJsonPath('summary.total_records', 1)
            ->assertJsonPath('summary.total_discount', 200)
            ->assertJsonPath('data.0.invoice_number', $invoice['invoice_number'])
            ->assertJsonPath('data.0.order_number', $ctx['order']->order_number)
            ->assertJsonPath('data.0.customer_name', 'Test Customer')
            ->assertJsonPath('data.0.discount_type', 'percentage')
            ->assertJsonPath('data.0.amount', 200)
            ->assertJsonPath('data.0.reason', 'Manager approval');

        $this->deleteJson('/api/v1/invoices/' . $invoice['uid'] . '/discounts/' . $discount['uid'])
            ->assertOk()
            ->assertJsonPath('invoice.total_amount', '1000.0000')
            ->assertJsonPath('invoice.outstanding_amount', '1000.0000');

        $this->assertSame(0, InvoiceDiscount::count());
        $this->assertDatabaseMissing('invoice_lines', [
            'invoice_id' => Invoice::where('uid', $invoice['uid'])->value('id'),
            'total_price' => -200,
        ]);
    }

    public function test_order_voucher_discounts_reduce_invoice_and_show_in_profit_report(): void
    {
        $ctx = $this->seedTenantContext();

        $order = $this->postJson('/api/v1/orders/create-from-voucher', [
            'customer_id' => $ctx['customer']->id,
            'currency_code' => 'PKR',
            'status' => 'order',
            'voucher' => [
                'active_sections' => ['flights'],
                'passengers' => [['name' => 'Discount Passenger']],
                'flights' => [['flight_no' => 'PK301', 'from' => 'LHE', 'to' => 'JED', 'date' => '2026-09-01']],
                'pricing' => [[
                    'pax_name' => 'Discount Passenger',
                    'vendor_id' => $ctx['vendor']->id,
                    'vendor_name' => $ctx['vendor']->name,
                    'flight_cost' => 700,
                    'flight_profit' => 300,
                    'flight_sales' => 1000,
                ]],
                'discounts' => [[
                    'discount_type' => 'percentage',
                    'percentage' => 10,
                    'reason' => 'Early booking',
                ]],
            ],
        ])->assertCreated()
            ->assertJsonPath('order.total_amount', '900.0000')
            ->json('order');

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order['id'],
            'description' => 'Discount: Early booking',
            'total_price' => -100,
        ]);

        $invoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $order['id'],
        ])->assertCreated()
            ->assertJsonPath('invoice.subtotal', '1000.0000')
            ->assertJsonPath('invoice.total_amount', '900.0000')
            ->json('invoice');

        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => Invoice::where('uid', $invoice['uid'])->value('id'),
            'description' => 'Discount: Early booking',
            'total_price' => -100,
        ]);

        $this->getJson('/api/v1/reports/profit?from_date=2020-01-01&to_date=2030-12-31&group_by=customer&entity_id=' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonPath('data.0.revenue', 900)
            ->assertJsonPath('data.0.discount', 100)
            ->assertJsonPath('data.0.cost', 700)
            ->assertJsonPath('data.0.profit', 200)
            ->assertJsonPath('details.0.discount', 100);
    }

    public function test_profit_report_service_date_uses_latest_checkout_or_return_flight(): void
    {
        $ctx = $this->seedTenantContext();

        $ctx['order']->update([
            'active_sections' => ['flights', 'hotels'],
            'meta' => [
                'active_sections' => ['flights', 'hotels'],
                'flights' => [
                    ['flight_no' => 'PK301', 'from' => 'LHE', 'to' => 'JED', 'date' => '2026-09-01'],
                    ['flight_no' => 'PK302', 'from' => 'JED', 'to' => 'LHE', 'date' => '2026-09-12'],
                ],
                'hotels' => [
                    ['hotel_name' => 'Makkah Stay', 'check_in' => '2026-09-02', 'check_out' => '2026-09-10'],
                ],
            ],
        ]);

        $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated();

        $this->getJson('/api/v1/reports/profit?from_date=2026-09-10&to_date=2026-09-10&date_by=service&group_by=customer&entity_id=' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonCount(0, 'details');

        $this->getJson('/api/v1/reports/profit?from_date=2026-09-12&to_date=2026-09-12&date_by=service&group_by=customer&entity_id=' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonPath('details.0.service_date', '2026-09-12');

        $ctx['order']->update([
            'meta' => [
                'active_sections' => ['flights', 'hotels'],
                'flights' => [
                    ['flight_no' => 'PK301', 'from' => 'LHE', 'to' => 'JED', 'date' => '2026-09-01'],
                    ['flight_no' => 'PK302', 'from' => 'JED', 'to' => 'LHE', 'date' => '2026-09-12'],
                ],
                'hotels' => [
                    ['hotel_name' => 'Makkah Stay', 'check_in' => '2026-09-02', 'check_out' => '2026-09-15'],
                ],
            ],
        ]);

        $this->getJson('/api/v1/reports/profit?from_date=2026-09-12&to_date=2026-09-12&date_by=service&group_by=customer&entity_id=' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonCount(0, 'details');

        $this->getJson('/api/v1/reports/profit?from_date=2026-09-15&to_date=2026-09-15&date_by=service&group_by=customer&entity_id=' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonPath('details.0.service_date', '2026-09-15');
    }

    public function test_company_can_create_more_than_fifty_invoices_per_month(): void
    {
        $ctx = $this->seedTenantContext();

        for ($i = 1; $i <= 50; $i++) {
            Invoice::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $ctx['tenant']->id,
                'company_id' => $ctx['company']->id,
                'order_id' => $ctx['order']->id,
                'customer_id' => $ctx['customer']->id,
                'invoice_number' => 'INV-LIMIT-' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency_code' => 'PKR',
                'subtotal' => 1000,
                'tax_amount' => 0,
                'total_amount' => 1000,
                'outstanding_amount' => 1000,
                'status' => 'issued',
                'fx_rate_to_base' => 1,
            ]);
        }

        $nextOrder = $ctx['order']->replicate();
        $nextOrder->uid = (string) Str::ulid();
        $nextOrder->order_number = 'ORD-' . fake()->unique()->numerify('######');
        $nextOrder->booking_reference = 'PNR' . fake()->numerify('#####');
        $nextOrder->save();

        foreach ($ctx['order']->items as $item) {
            $nextItem = $item->replicate();
            $nextItem->uid = (string) Str::ulid();
            $nextItem->order_id = $nextOrder->id;
            $nextItem->save();
        }

        $response = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $nextOrder->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);
    }

    public function test_invoiced_order_edit_requires_confirmation_and_creates_new_order_for_manual_invoice(): void
    {
        $ctx = $this->seedTenantContext();

        $originalInvoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated()->json('invoice');

        $this->postJson('/api/v1/receipts/customer/record', [
            'invoice_uid' => $originalInvoice['uid'],
            'amount' => 400,
            'payment_method' => 'cash',
        ])->assertCreated();
        $receipt = Receipt::where('customer_id', $ctx['customer']->id)->firstOrFail();

        $payload = [
            'customer_id' => $ctx['customer']->id,
            'status' => 'invoice',
            'currency_code' => 'PKR',
            'total_amount' => 1250,
            'notes' => 'Edited after invoice',
            'booking_reference' => 'REV123',
            'voucher' => [
                'booking_reference' => 'REV123',
                'active_sections' => ['other_services'],
                'other_services' => [[
                    'description' => 'Revised support package',
                    'sales' => 1250,
                ]],
            ],
        ];

        $this->patchJson('/api/v1/orders/' . $ctx['order']->uid, $payload)
            ->assertStatus(409)
            ->assertJsonPath('requires_invoice_revision', true)
            ->assertJsonPath('invoice.invoice_number', $originalInvoice['invoice_number']);

        $this->assertSame(1, Invoice::where('order_id', $ctx['order']->id)->count());

        $response = $this->patchJson('/api/v1/orders/' . $ctx['order']->uid, [
            ...$payload,
            'confirm_invoice_revision' => true,
        ])->assertOk()
            ->assertJsonPath('new_order_created', true)
            ->assertJsonPath('invoice_revised', false)
            ->assertJsonPath('invoice', null)
            ->assertJsonPath('voided_invoice.invoice_number', $originalInvoice['invoice_number']);

        $newOrderUid = $response->json('order.uid');
        $newOrderId = $response->json('order.id');
        $newOrderNumber = $response->json('order.order_number');

        $this->assertNotSame($ctx['order']->uid, $newOrderUid);
        $this->assertNotSame($ctx['order']->order_number, $newOrderNumber);
        $this->assertDatabaseHas('invoices', [
            'uid' => $originalInvoice['uid'],
            'status' => 'cancel',
            'total_amount' => 0,
            'outstanding_amount' => 0,
        ]);
        $this->assertDatabaseHas('orders', [
            'uid' => $ctx['order']->uid,
            'status' => 'cancel',
        ]);
        $this->assertDatabaseHas('orders', [
            'uid' => $newOrderUid,
            'status' => 'order',
            'total_amount' => 1250,
        ]);
        $this->assertDatabaseMissing('invoice_settlements', [
            'invoice_id' => $originalInvoice['id'],
            'reference_document_id' => $receipt->id,
            'reference_document_type' => Receipt::class,
        ]);
        $this->getJson('/api/v1/receipts/customer')
            ->assertOk()
            ->assertJsonPath('data.0.uid', $receipt->uid)
            ->assertJsonPath('data.0.remaining_amount', 400);
        $this->assertSame(1, Invoice::count());
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => Invoice::where('uid', $originalInvoice['uid'])->value('id'),
            'unit_price' => 0,
            'total_price' => 0,
        ]);
        $cancelledNotes = Invoice::where('uid', $originalInvoice['uid'])->value('notes');
        $this->assertStringContainsString($newOrderNumber, $cancelledNotes);
        $this->assertStringContainsString('Revenue breakup:', $cancelledNotes);
        $this->assertStringContainsString('Costing breakup:', $cancelledNotes);

        $this->getJson('/api/v1/invoices')
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->getJson('/api/v1/reports/cancelled?from_date=2020-01-01&to_date=2030-12-31')
            ->assertOk()
            ->assertJsonPath('summary.total_records', 1)
            ->assertJsonPath('data.0.invoice_number', $originalInvoice['invoice_number']);

        $this->getJson('/api/v1/orders/' . $newOrderUid)
            ->assertOk()
            ->assertJsonPath('invoice', null)
            ->assertJsonPath('status', 'order');

        $manualInvoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $newOrderId,
        ])->assertCreated()->json('invoice');

        $this->assertDatabaseHas('invoices', [
            'uid' => $manualInvoice['uid'],
            'order_id' => $newOrderId,
            'status' => 'issued',
            'total_amount' => 1250,
            'outstanding_amount' => 1250,
        ]);

        $this->postJson('/api/v1/receipts/customer/' . $receipt->uid . '/allocate-advance', [
            'date' => '2026-06-20',
            'allocations' => [
                ['invoice_id' => $manualInvoice['id'], 'amount' => 400],
            ],
        ])->assertOk()
            ->assertJsonPath('receipt.settlements.0.invoice.invoice_number', $manualInvoice['invoice_number']);
        $this->assertDatabaseHas('invoices', [
            'id' => $manualInvoice['id'],
            'status' => 'partial_paid',
            'outstanding_amount' => 850,
        ]);
    }

    public function test_invoiced_order_can_create_negative_refund_request_referencing_original_order(): void
    {
        $ctx = $this->seedTenantContext();

        $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated();

        $response = $this->postJson('/api/v1/orders/' . $ctx['order']->uid . '/refund-request')
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('original_order_uid', $ctx['order']->uid)
            ->assertJsonPath('order.status', 'refund_request')
            ->assertJsonPath('order.total_amount', '-1000.0000')
            ->assertJsonPath('order.booking_reference', $ctx['order']->order_number);

        $refundOrderId = $response->json('order.id');
        $refundOrderUid = $response->json('order.uid');

        $this->assertNotSame($ctx['order']->uid, $refundOrderUid);
        $this->assertDatabaseHas('orders', [
            'id' => $ctx['order']->id,
            'status' => 'invoice',
            'total_amount' => 1000,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $refundOrderId,
            'status' => 'refund_request',
            'booking_reference' => $ctx['order']->order_number,
            'total_amount' => -1000,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $refundOrderId,
            'description' => 'Flight Ticket',
            'unit_price' => -1000,
            'total_price' => -1000,
        ]);

        $refundOrder = Order::findOrFail($refundOrderId);
        $this->assertSame($ctx['order']->uid, $refundOrder->meta['refund_of_order_uid']);
        $this->assertSame($ctx['order']->order_number, $refundOrder->meta['refund_of_order_number']);

        $orders = $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonFragment(['id' => $refundOrderId, 'status' => 'refund_request'])
            ->json('data');
        $this->assertFalse(collect($orders)->contains(fn (array $order) => (int) $order['id'] === (int) $ctx['order']->id));

        $pendingCustomerStatement = $this->getJson('/api/v1/statements/customer/' . $ctx['customer']->id)
            ->assertOk()
            ->json('transactions');
        $this->assertFalse(collect($pendingCustomerStatement)->contains(
            fn (array $transaction) => $transaction['reference'] === $refundOrder->order_number
        ));

        $pendingVendorStatement = $this->getJson('/api/v1/statements/vendor/' . $ctx['vendor']->id)
            ->assertOk()
            ->json('transactions');
        $this->assertFalse(collect($pendingVendorStatement)->contains(
            fn (array $transaction) => $transaction['reference'] === $refundOrder->order_number
        ));

        $this->patchJson('/api/v1/orders/' . $refundOrder->uid, [
            'customer_id' => $ctx['customer']->id,
            'status' => 'refund',
            'currency_code' => 'PKR',
            'total_amount' => -1000,
            'notes' => 'Refund completed',
        ])->assertOk()
            ->assertJsonPath('order.status', 'refund');

        $completedRefundRows = $this->getJson('/api/v1/orders')
            ->assertOk()
            ->json('data');
        $this->assertFalse(collect($completedRefundRows)->contains(fn (array $order) => (int) $order['id'] === (int) $refundOrderId));

        $this->getJson('/api/v1/invoices?status=refund')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'refund-order-' . $refundOrderId,
                'status' => 'refund',
                'is_refund_order' => true,
            ]);

        $this->getJson('/api/v1/statements/customer/' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'refund',
                'reference' => $refundOrder->order_number,
                'refunds' => 1000,
            ]);

        $this->getJson('/api/v1/statements/vendor/' . $ctx['vendor']->id)
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'vendor_refund',
                'reference' => $refundOrder->order_number,
                'vendor_refunds' => 1000,
            ])
            ->assertJsonPath('summary.outstanding_balance', 0);
    }

    public function test_refund_request_can_be_invoiced_with_negative_total_and_remains_allocatable(): void
    {
        $ctx = $this->seedTenantContext();

        $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated();

        $refundOrder = $this->postJson('/api/v1/orders/' . $ctx['order']->uid . '/refund-request')
            ->assertCreated()
            ->json('order');

        $refundInvoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $refundOrder['id'],
            'invoice_date' => '2026-06-24',
        ])->assertCreated()
            ->assertJsonPath('invoice.total_amount', '-1000.0000')
            ->assertJsonPath('invoice.outstanding_amount', '0.0000')
            ->assertJsonPath('invoice.lines.0.total_price', '-1000.0000')
            ->json('invoice');

        $this->assertDatabaseHas('orders', [
            'id' => $refundOrder['id'],
            'status' => 'refund',
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => $refundInvoice['id'],
            'order_id' => $refundOrder['id'],
            'total_amount' => -1000,
            'outstanding_amount' => 0,
        ]);

        $this->getJson('/api/v1/payments/refund-allocations/customer/' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonPath('outstanding_total', 1000)
            ->assertJsonPath('data.0.id', $refundOrder['id']);

        $this->getJson('/api/v1/payments/refund-allocations/vendor/' . $ctx['vendor']->id)
            ->assertOk()
            ->assertJsonPath('outstanding_total', 1000)
            ->assertJsonPath('data.0.id', $refundOrder['id']);

        $invoiceRows = $this->getJson('/api/v1/invoices?status=refund')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $refundInvoice['id'],
                'status' => 'refund',
                'is_refund_order' => true,
                'total_amount' => '-1000.0000',
            ])
            ->json('data');

        $this->assertFalse(collect($invoiceRows)->contains(fn (array $row) => $row['id'] === 'refund-order-' . $refundOrder['id']));
    }

    public function test_customer_refund_can_adjust_another_invoice_without_payment(): void
    {
        $ctx = $this->seedTenantContext();

        $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
            'invoice_date' => '2026-06-24',
        ])->assertCreated();

        $refundOrder = $this->postJson('/api/v1/orders/' . $ctx['order']->uid . '/refund-request')
            ->assertCreated()
            ->json('order');

        $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $refundOrder['id'],
            'invoice_date' => '2026-06-24',
        ])->assertCreated();

        $secondOrder = $ctx['order']->replicate();
        $secondOrder->uid = (string) Str::ulid();
        $secondOrder->order_number = 'ORD-' . fake()->unique()->numerify('######');
        $secondOrder->booking_reference = 'PNR' . fake()->numerify('#####');
        $secondOrder->status = 'order';
        $secondOrder->save();
        foreach ($ctx['order']->items as $item) {
            $copy = $item->replicate();
            $copy->uid = (string) Str::ulid();
            $copy->order_id = $secondOrder->id;
            $copy->save();
        }

        $secondInvoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $secondOrder->id,
            'invoice_date' => '2026-06-25',
        ])->assertCreated()->json('invoice');

        $response = $this->postJson('/api/v1/payments/refund-allocations/customer-adjustment', [
            'customer_id' => $ctx['customer']->id,
            'amount' => 1000,
            'adjustment_date' => '2026-06-26',
            'description' => 'Adjusted refund against next sale',
            'allocations' => [
                ['order_id' => $refundOrder['id'], 'amount' => 1000],
            ],
            'invoice_allocations' => [
                ['invoice_uid' => $secondInvoice['uid'], 'amount' => 1000],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'adjustment.refund_allocations')
            ->assertJsonCount(1, 'adjustment.settlements');

        $this->assertSame(0, Payment::count());
        $this->assertDatabaseHas('refund_allocations', [
            'order_id' => $refundOrder['id'],
            'payment_id' => null,
            'receipt_id' => null,
            'allocation_type' => RefundAllocation::CUSTOMER_PAYMENT,
            'amount' => 1000,
        ]);
        $this->assertDatabaseHas('invoice_settlements', [
            'invoice_id' => $secondInvoice['id'],
            'amount_received' => 1000,
            'reference_document_type' => RefundAllocation::class,
            'notes' => 'Adjusted refund against next sale',
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => $secondInvoice['id'],
            'status' => 'paid',
            'outstanding_amount' => 0,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $secondOrder->id,
            'status' => 'paid',
        ]);

        $this->getJson('/api/v1/payments/refund-allocations/customer/' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonPath('outstanding_total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_full_refund_workflow_invoices_negative_refund_with_matching_cost_and_profit_impact(): void
    {
        $ctx = $this->seedTenantContext();

        OrderVendorCost::create([
            'tenant_id' => $ctx['tenant']->id,
            'order_id' => $ctx['order']->id,
            'vendor_id' => $ctx['vendor']->id,
            'service_type' => 'flight',
            'service_index' => 0,
            'amount' => 700,
        ]);

        $invoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
            'invoice_date' => '2026-06-24',
        ])->assertCreated()->json('invoice');

        $this->getJson('/api/v1/invoices?search=' . $invoice['invoice_number'])
            ->assertOk()
            ->assertJsonPath('data.0.can_create_refund_request', true);

        $refundOrder = $this->postJson('/api/v1/orders/' . $ctx['order']->uid . '/refund-request')
            ->assertCreated()
            ->assertJsonPath('order.total_amount', '-1000.0000')
            ->json('order');

        $refundInvoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $refundOrder['id'],
            'invoice_date' => '2026-06-24',
        ])->assertCreated()
            ->assertJsonPath('invoice.total_amount', '-1000.0000')
            ->assertJsonPath('invoice.lines.0.total_price', '-1000.0000')
            ->json('invoice');

        $this->assertDatabaseHas('orders', [
            'id' => $refundOrder['id'],
            'status' => 'refund',
            'total_amount' => -1000,
        ]);
        $this->assertDatabaseHas('order_vendor_costs', [
            'order_id' => $refundOrder['id'],
            'vendor_id' => $ctx['vendor']->id,
            'amount' => -700,
        ]);

        $this->getJson('/api/v1/invoices?search=' . $invoice['invoice_number'])
            ->assertOk()
            ->assertJsonPath('data.0.can_create_refund_request', false);

        $this->postJson('/api/v1/orders/' . $ctx['order']->uid . '/refund-request')
            ->assertUnprocessable()
            ->assertJsonPath('error', 'A refund request or refund order already exists for this invoice.');

        $customerStatement = $this->getJson('/api/v1/statements/customer/' . $ctx['customer']->id)
            ->assertOk();
        $statementRefundInvoice = collect($customerStatement->json('customer_currency_invoices'))
            ->firstWhere('invoice_number', $refundInvoice['invoice_number']);
        $this->assertSame('refund', $statementRefundInvoice['status']);
        $customerRefunds = collect($customerStatement->json('transactions'))->where('type', 'refund')->values();
        $this->assertCount(1, $customerRefunds);
        $this->assertSame($refundInvoice['invoice_number'], $customerRefunds[0]['reference']);
        $this->assertSame(1000.0, (float) $customerRefunds[0]['refunds']);

        $vendorStatement = $this->getJson('/api/v1/statements/vendor/' . $ctx['vendor']->id)
            ->assertOk()
            ->assertJsonPath('summary.outstanding_balance', 0)
            ->json('transactions');
        $vendorRefunds = collect($vendorStatement)->where('type', 'vendor_refund')->values();
        $this->assertCount(1, $vendorRefunds);
        $this->assertSame($refundOrder['order_number'], $vendorRefunds[0]['reference']);
        $this->assertSame(700.0, (float) $vendorRefunds[0]['vendor_refunds']);

        $profit = $this->getJson('/api/v1/reports/profit?from_date=2020-01-01&to_date=2030-12-31&group_by=customer&entity_id=' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonPath('data.0.revenue', 0)
            ->assertJsonPath('data.0.cost', 0)
            ->assertJsonPath('data.0.profit', 0)
            ->json('details');
        $refundProfitRow = collect($profit)->firstWhere('order_id', $refundOrder['id']);
        $this->assertSame(-1000.0, (float) $refundProfitRow['revenue']);
        $this->assertSame(-700.0, (float) $refundProfitRow['cost']);
        $this->assertSame(-300.0, (float) $refundProfitRow['profit']);
    }

    public function test_refund_can_be_deleted_directly_from_invoice_action_unless_allocated(): void
    {
        $ctx = $this->seedTenantContext();

        $invoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated()->json('invoice');

        $refundOrder = $this->postJson('/api/v1/orders/' . $ctx['order']->uid . '/refund-request')
            ->assertCreated()
            ->json('order');
        $refundInvoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $refundOrder['id'],
        ])->assertCreated()->json('invoice');

        $this->getJson('/api/v1/invoices?search=' . $invoice['invoice_number'])
            ->assertOk()
            ->assertJsonPath('data.0.has_refund_order', true)
            ->assertJsonPath('data.0.refund_order_number', $refundOrder['order_number'])
            ->assertJsonPath('data.0.can_delete_refund', true);

        $this->deleteJson('/api/v1/invoices/' . $invoice['uid'] . '/refund')
            ->assertOk()
            ->assertJsonPath('refund_order_number', $refundOrder['order_number']);

        $this->assertDatabaseMissing('invoices', ['uid' => $refundInvoice['uid']]);
        $this->assertNull(Order::withTrashed()->find($refundOrder['id']));

        $this->getJson('/api/v1/invoices?search=' . $invoice['invoice_number'])
            ->assertOk()
            ->assertJsonPath('data.0.can_create_refund_request', true)
            ->assertJsonPath('data.0.has_refund_order', false)
            ->assertJsonPath('data.0.can_delete_refund', false);

        $secondRefundOrder = $this->postJson('/api/v1/orders/' . $ctx['order']->uid . '/refund-request')
            ->assertCreated()
            ->json('order');
        $secondRefundInvoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $secondRefundOrder['id'],
        ])->assertCreated()->json('invoice');

        $this->postJson('/api/v1/payments/refund-allocations/customer-payment', [
            'customer_id' => $ctx['customer']->id,
            'amount' => 100,
            'payment_method' => 'cash',
            'allocations' => [
                ['order_id' => $secondRefundOrder['id'], 'amount' => 100],
            ],
        ])->assertCreated();

        $this->getJson('/api/v1/invoices?search=' . $secondRefundInvoice['invoice_number'])
            ->assertOk()
            ->assertJsonPath('data.0.has_refund_order', true)
            ->assertJsonPath('data.0.can_delete_refund', false);

        $this->deleteJson('/api/v1/invoices/' . $secondRefundInvoice['uid'] . '/refund')
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Delete customer refund payments and vendor refund receipts before deleting this refund.');

        $this->assertDatabaseHas('invoices', ['uid' => $secondRefundInvoice['uid']]);
        $this->assertFalse(Order::withTrashed()->findOrFail($secondRefundOrder['id'])->trashed());
    }

    public function test_refund_status_order_without_invoice_can_open_as_virtual_invoice(): void
    {
        $ctx = $this->seedTenantContext();

        $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated();

        $refund = $this->postJson('/api/v1/orders/' . $ctx['order']->uid . '/refund-request')
            ->assertCreated()
            ->json('order');

        Order::whereKey($refund['id'])->update(['status' => 'refund']);

        $this->assertDatabaseMissing('invoices', [
            'order_id' => $refund['id'],
        ]);

        $this->getJson('/api/v1/invoices?status=refund')
            ->assertOk()
            ->assertJsonPath('data.0.uid', $refund['uid'])
            ->assertJsonPath('data.0.status', 'refund')
            ->assertJsonPath('data.0.is_refund_order', true)
            ->assertJsonPath('data.0.is_virtual_invoice', true);

        $this->getJson('/api/v1/invoices/' . $refund['uid'])
            ->assertOk()
            ->assertJsonPath('uid', $refund['uid'])
            ->assertJsonPath('invoice_number', 'Refund ' . $refund['order_number'])
            ->assertJsonPath('status', 'refund')
            ->assertJsonPath('is_virtual_invoice', true)
            ->assertJsonPath('lines.0.total_price', '-1000.0000');
    }

    public function test_refund_allocation_records_customer_payment_and_vendor_receipt_against_refund_request(): void
    {
        $ctx = $this->seedTenantContext();

        $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated();

        $refund = $this->postJson('/api/v1/orders/' . $ctx['order']->uid . '/refund-request')
            ->assertCreated()
            ->json('order');

        $this->getJson('/api/v1/payments/refund-allocations/customer/' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonPath('outstanding_total', 1000)
            ->assertJsonPath('data.0.id', $refund['id']);

        $customerPayment = $this->postJson('/api/v1/payments/refund-allocations/customer-payment', [
            'customer_id' => $ctx['customer']->id,
            'amount' => 400,
            'payment_method' => 'cash',
            'payment_date' => '2026-06-22',
            'description' => 'Refund paid to customer',
            'allocations' => [
                ['order_id' => $refund['id'], 'amount' => 400],
            ],
        ])->assertCreated()
            ->assertJsonPath('payment.amount', '400.0000')
            ->assertJsonPath('payment.refund_allocations.0.order_id', $refund['id'])
            ->json('payment');

        $this->assertDatabaseHas('refund_allocations', [
            'payment_id' => $customerPayment['id'],
            'order_id' => $refund['id'],
            'allocation_type' => 'customer_payment',
            'amount' => 400,
        ]);

        $this->getJson('/api/v1/payments/refund-allocations/customer/' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonPath('outstanding_total', 600)
            ->assertJsonPath('data.0.outstanding_amount', 600);

        $this->postJson('/api/v1/payments/refund-allocations/customer-payment', [
            'customer_id' => $ctx['customer']->id,
            'amount' => 700,
            'payment_method' => 'cash',
            'allocations' => [
                ['order_id' => $refund['id'], 'amount' => 700],
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('error', 'An allocation exceeds the selected customer refund balance.');

        $vendorReceipt = $this->postJson('/api/v1/payments/refund-allocations/vendor-receipt', [
            'vendor_id' => $ctx['vendor']->id,
            'amount' => 250,
            'payment_method' => 'cash',
            'receipt_date' => '2026-06-23',
            'description' => 'Vendor paid refund value',
            'allocations' => [
                ['order_id' => $refund['id'], 'amount' => 250],
            ],
        ])->assertCreated()
            ->assertJsonPath('receipt.amount', '250.0000')
            ->assertJsonPath('receipt.refund_allocations.0.order_id', $refund['id'])
            ->json('receipt');

        $this->assertDatabaseHas('refund_allocations', [
            'receipt_id' => $vendorReceipt['id'],
            'order_id' => $refund['id'],
            'allocation_type' => 'vendor_receipt',
            'amount' => 250,
        ]);

        $this->getJson('/api/v1/payments/refund-allocations/vendor/' . $ctx['vendor']->id)
            ->assertOk()
            ->assertJsonPath('outstanding_total', 750)
            ->assertJsonPath('data.0.outstanding_amount', 750);

        $this->getJson('/api/v1/statements/customer/' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'payment',
                'customer_payments' => 400,
            ]);

        $this->getJson('/api/v1/statements/vendor/' . $ctx['vendor']->id)
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'receipt',
                'vendor_receipts' => 250,
            ]);
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

    public function test_cancelled_invoices_cannot_receive_customer_receipts_or_advances(): void
    {
        $ctx = $this->seedTenantContext();

        $invoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated()->json('invoice');

        Invoice::where('uid', $invoice['uid'])->update([
            'status' => 'cancel',
            'outstanding_amount' => 1000,
            'advance_balance' => 200,
        ]);
        $ctx['order']->update(['status' => 'cancel']);

        $this->postJson('/api/v1/receipts/customer/record', [
            'invoice_uid' => $invoice['uid'],
            'amount' => 100,
            'payment_method' => 'cash',
        ])->assertStatus(422);

        $this->postJson('/api/v1/receipts/customer/advance', [
            'invoice_uid' => $invoice['uid'],
            'amount' => 100,
            'payment_method' => 'cash',
        ])->assertStatus(422);

        $this->postJson('/api/v1/payments/apply-advance', [
            'invoice_uid' => $invoice['uid'],
            'advance_amount' => 50,
        ])->assertStatus(422);
    }

    public function test_customer_advance_receipt_can_be_recorded_without_invoice(): void
    {
        $ctx = $this->seedTenantContext();

        $response = $this->postJson('/api/v1/receipts/customer/advance', [
            'customer_id' => $ctx['customer']->id,
            'amount' => 350,
            'payment_method' => 'cash',
            'payment_date' => '2026-06-21',
            'reference_number' => 'ADV-001',
            'narration' => 'Trip deposit before invoice',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('receipt.customer.name', 'Test Customer')
            ->assertJsonPath('receipt.amount', '350.0000')
            ->assertJsonPath('receipt.reference_number', 'ADV-001');

        $this->assertDatabaseHas('receipts', [
            'customer_id' => $ctx['customer']->id,
            'amount' => 350,
            'description' => 'Trip deposit before invoice',
        ]);

        $this->getJson('/api/v1/receipts/customer')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.settlements', []);

        $this->getJson('/api/v1/statements/customer/' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'receipt',
                'reference' => $response->json('receipt.receipt_number'),
                'customer_receipts' => 350,
            ]);
    }

    public function test_customer_advance_receipt_can_be_allocated_later(): void
    {
        $ctx = $this->seedTenantContext();

        $advance = $this->postJson('/api/v1/receipts/customer/advance', [
            'customer_id' => $ctx['customer']->id,
            'amount' => 350,
            'payment_method' => 'cash',
            'payment_date' => '2026-06-21',
            'reference_number' => 'ADV-LATER',
        ])->assertCreated()->json('receipt');

        $invoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated()->json('invoice');

        $this->postJson('/api/v1/receipts/customer/' . $advance['uid'] . '/allocate-advance', [
            'date' => '2026-06-22',
            'allocations' => [
                ['invoice_id' => $invoice['id'], 'amount' => 250],
            ],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('receipt.settlements.0.invoice.invoice_number', $invoice['invoice_number']);

        $this->assertDatabaseHas('invoice_settlements', [
            'invoice_id' => $invoice['id'],
            'amount_received' => 250,
            'reference_document_type' => Receipt::class,
            'reference_document_id' => $advance['id'],
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice['id'],
            'outstanding_amount' => 750,
            'status' => 'partial_paid',
        ]);

        $this->getJson('/api/v1/receipts/customer')
            ->assertOk()
            ->assertJsonPath('data.0.remaining_amount', 100);
    }

    public function test_vendor_advance_payment_can_be_allocated_later(): void
    {
        $ctx = $this->seedTenantContext();

        $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated();

        $advance = $this->postJson('/api/v1/payments/vendor', [
            'vendor_id' => $ctx['vendor']->id,
            'amount' => 300,
            'payment_method' => 'cash',
            'payment_date' => '2026-06-23',
            'narration' => 'Supplier deposit',
        ])->assertCreated()
            ->assertJsonPath('payment.amount', '300.0000')
            ->json('payment');

        $this->assertSame(0, VendorPaymentAllocation::count());

        $this->getJson('/api/v1/payments/vendor')
            ->assertOk()
            ->assertJsonPath('data.0.remaining_amount', 300);

        $payable = $this->getJson('/api/v1/payments/vendor/' . $ctx['vendor']->id . '/payables?ignore_legacy_balance=1')
            ->assertOk()
            ->assertJsonPath('outstanding_total', 1000)
            ->json('data.0');

        $this->postJson('/api/v1/payments/vendor/payment/' . $advance['uid'] . '/allocate-advance', [
            'allocations' => [
                ['order_id' => $payable['id'], 'amount' => 250],
            ],
        ])->assertOk()
            ->assertJsonPath('payment.allocations.0.amount', '250.0000');

        $this->assertDatabaseHas('vendor_payment_allocations', [
            'payment_id' => $advance['id'],
            'order_id' => $payable['id'],
            'amount' => 250,
        ]);

        $this->getJson('/api/v1/payments/vendor')
            ->assertOk()
            ->assertJsonPath('data.0.remaining_amount', 50);

        $this->postJson('/api/v1/payments/vendor/payment/' . $advance['uid'] . '/allocate-advance', [
            'allocations' => [
                ['order_id' => $payable['id'], 'amount' => 75],
            ],
        ])->assertStatus(422);
    }

    public function test_customer_statement_summary_uses_net_statement_balance(): void
    {
        $ctx = $this->seedTenantContext();

        $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated();

        $this->postJson('/api/v1/receipts/customer/advance', [
            'customer_id' => $ctx['customer']->id,
            'amount' => 200,
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->postJson('/api/v1/payments/customer', [
            'customer_id' => $ctx['customer']->id,
            'amount' => 50,
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->getJson('/api/v1/statements/customer/' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonPath('summary.total_amount', 1000)
            ->assertJsonPath('summary.total_paid', 150)
            ->assertJsonPath('summary.total_receipts', 200)
            ->assertJsonPath('summary.total_payments', 50)
            ->assertJsonPath('summary.total_outstanding', 850);

        $this->getJson('/api/v1/reports/dashboard-upcoming?days=7')
            ->assertOk()
            ->assertJsonPath('finance.summary.invoiced', 1000)
            ->assertJsonPath('finance.summary.refund', 0)
            ->assertJsonPath('finance.summary.collected', 200)
            ->assertJsonPath('finance.summary.customer_receipts', 200)
            ->assertJsonPath('finance.summary.customer_payments', 50)
            ->assertJsonPath('finance.summary.outstanding', 850)
            ->assertJsonPath('finance.summary.purchase', 1000)
            ->assertJsonPath('finance.summary.paid', 50)
            ->assertJsonPath('finance.outstanding.summary.customer_total', 850);
    }

    public function test_dashboard_refund_this_month_includes_invoice_refunds(): void
    {
        $ctx = $this->seedTenantContext();

        $invoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated()->json('invoice');

        $this->postJson('/api/v1/receipts/customer/record', [
            'invoice_uid' => $invoice['uid'],
            'amount' => 300,
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->postJson('/api/v1/payments/customer/refund', [
            'invoice_uid' => $invoice['uid'],
            'amount' => 100,
            'reason' => 'Partial service refund',
        ])->assertCreated();

        $this->getJson('/api/v1/reports/dashboard-upcoming?days=7')
            ->assertOk()
            ->assertJsonPath('finance.summary.invoiced', 1000)
            ->assertJsonPath('finance.summary.refund', 100)
            ->assertJsonPath('finance.summary.collected', 300)
            ->assertJsonPath('finance.summary.paid', 100)
            ->assertJsonPath('finance.summary.purchase', 1000)
            ->assertJsonPath('finance.outstanding.summary.customer_total', 700);
    }

    public function test_vendor_statement_summary_splits_payments_and_receipts(): void
    {
        $ctx = $this->seedTenantContext();

        $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated();

        $this->postJson('/api/v1/payments/vendor', [
            'vendor_id' => $ctx['vendor']->id,
            'amount' => 300,
            'payment_method' => 'cash',
            'payment_date' => now()->toDateString(),
        ])->assertCreated();

        $this->postJson('/api/v1/receipts/vendor', [
            'vendor_id' => $ctx['vendor']->id,
            'amount' => 50,
            'payment_method' => 'cash',
            'receipt_date' => now()->toDateString(),
        ])->assertCreated();

        $this->getJson('/api/v1/statements/vendor/' . $ctx['vendor']->id)
            ->assertOk()
            ->assertJsonPath('summary.period_payables', 1000)
            ->assertJsonPath('summary.period_paid', 300)
            ->assertJsonPath('summary.period_payments', 300)
            ->assertJsonPath('summary.period_receipts', 50)
            ->assertJsonPath('summary.outstanding_balance', 750);
    }

    public function test_unattached_customers_and_vendors_can_be_updated_and_deleted(): void
    {
        $ctx = $this->seedTenantContext();

        $customer = $this->postJson('/api/v1/customers', [
            'name' => 'Draft Customer',
            'type' => 'B2C',
            'email' => 'draft-customer@test.local',
            'phone' => '+92-300-3333333',
        ])->assertCreated()->json('customer');

        $this->patchJson('/api/v1/customers/' . $customer['uid'], [
            'name' => 'Edited Customer',
            'type' => 'B2B',
            'email' => 'edited-customer@test.local',
            'phone' => '+92-300-4444444',
            'address' => 'Edited address',
            'city' => 'Lahore',
            'country_code' => 'pk',
        ])->assertOk()
            ->assertJsonPath('customer.name', 'Edited Customer')
            ->assertJsonPath('customer.type', 'B2B')
            ->assertJsonPath('customer.country_code', 'PK')
            ->assertJsonPath('customer.can_manage', true);

        $this->deleteJson('/api/v1/customers/' . $customer['uid'])->assertOk();
        $this->assertDatabaseMissing('customers', ['uid' => $customer['uid']]);

        $vendor = $this->postJson('/api/v1/vendors', [
            'name' => 'Draft Vendor',
            'type' => 'B2C',
            'email' => 'draft-vendor@test.local',
            'payment_terms' => '7 days',
        ])->assertCreated()->json('vendor');

        $this->patchJson('/api/v1/vendors/' . $vendor['uid'], [
            'name' => 'Edited Vendor',
            'type' => 'B2B',
            'email' => 'edited-vendor@test.local',
            'phone' => '+92-300-5555555',
            'address' => 'Vendor address',
            'city' => 'Karachi',
            'country_code' => 'pk',
            'payment_terms' => '14 days',
        ])->assertOk()
            ->assertJsonPath('vendor.name', 'Edited Vendor')
            ->assertJsonPath('vendor.payment_terms', '14 days')
            ->assertJsonPath('vendor.can_manage', true);

        $this->deleteJson('/api/v1/vendors/' . $vendor['uid'])->assertOk();
        $this->assertDatabaseMissing('vendors', ['uid' => $vendor['uid']]);

        $this->patchJson('/api/v1/customers/' . $ctx['customer']->uid, [
            'name' => 'Blocked Customer',
            'type' => 'B2C',
        ])->assertStatus(422);
        $this->deleteJson('/api/v1/customers/' . $ctx['customer']->uid)->assertStatus(422);

        $this->patchJson('/api/v1/vendors/' . $ctx['vendor']->uid, [
            'name' => 'Blocked Vendor',
            'type' => 'B2B',
        ])->assertStatus(422);
        $this->deleteJson('/api/v1/vendors/' . $ctx['vendor']->uid)->assertStatus(422);
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
        $this->postJson('/api/v1/invoices/create-from-order', ['order_id' => $ctx['order']->id])->assertCreated();
        $this->postJson('/api/v1/invoices/create-from-order', ['order_id' => $secondOrder->id])->assertCreated();

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

    public function test_cancelled_orders_are_not_available_for_vendor_payment_allocation(): void
    {
        $ctx = $this->seedTenantContext();

        $invoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated()->json('invoice');

        Invoice::where('uid', $invoice['uid'])->update(['status' => 'cancel']);
        $ctx['order']->update(['status' => 'cancel']);

        $this->getJson('/api/v1/payments/vendor/' . $ctx['vendor']->id . '/payables')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('outstanding_total', 0);

        $this->postJson('/api/v1/payments/vendor', [
            'vendor_id' => $ctx['vendor']->id,
            'amount' => 100,
            'payment_method' => 'cash',
            'payment_date' => '2026-06-19',
            'allocations' => [
                ['order_id' => $ctx['order']->id, 'amount' => 100],
            ],
        ])->assertStatus(422);
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

    public function test_voucher_share_link_is_publicly_readable(): void
    {
        $ctx = $this->seedTenantContext();

        $share = $this->postJson('/api/v1/orders/' . $ctx['order']->uid . '/share')
            ->assertOk()
            ->assertJsonStructure(['share_url', 'share_token'])
            ->json();

        auth()->guard('sanctum')->forgetUser();
        auth()->forgetGuards();

        $this->getJson('/api/v1/shared-vouchers/' . $share['share_token'])
            ->assertOk()
            ->assertJsonPath('uid', $ctx['order']->uid)
            ->assertJsonPath('order_number', $ctx['order']->order_number);
    }

    public function test_reference_search_finds_orders_invoices_and_voucher_metadata(): void
    {
        $ctx = $this->seedTenantContext();
        $ctx['order']->update([
            'booking_reference' => 'REFPNR1',
            'voucher_no' => 'FOLDER-779',
            'meta' => [
                'passengers' => [
                    ['name' => 'Search Passenger', 'phone' => '03001234567', 'email' => 'search@example.com', 'postal_code' => '54010', 'ticket_no' => 'TICKET-778899'],
                ],
                'flights' => [
                    ['gds_pnr' => 'REFPNR1', 'pnr' => 'AIRREF1', 'to' => 'JED', 'date' => '2026-08-01'],
                ],
                'pricing' => [
                    ['pax_name' => 'Search Passenger', 'flight_ticket_no' => 'TICKET-778899'],
                ],
            ],
        ]);

        $invoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated()->json('invoice');

        $pnrResults = $this->getJson('/api/v1/reference-search?pnr=REFPNR1')
            ->assertOk()
            ->assertJsonFragment(['type' => 'Invoice', 'reference' => $invoice['invoice_number']])
            ->json('data');

        $this->assertFalse(collect($pnrResults)->contains(fn (array $row): bool => $row['type'] === 'Order' && $row['reference'] === $ctx['order']->order_number));

        $this->getJson('/api/v1/reference-search?invoice_no=' . urlencode($invoice['invoice_number']))
            ->assertOk()
            ->assertJsonFragment(['type' => 'Invoice', 'reference' => $invoice['invoice_number']]);

        $this->getJson('/api/v1/reference-search?order_no=' . urlencode($ctx['order']->order_number))
            ->assertOk()
            ->assertJsonFragment(['type' => 'Invoice', 'reference' => $invoice['invoice_number']]);

        $this->getJson('/api/v1/reference-search?q=' . urlencode($ctx['order']->order_number))
            ->assertOk()
            ->assertJsonFragment(['type' => 'Invoice', 'reference' => $invoice['invoice_number']]);

        $this->getJson('/api/v1/reference-search?q=invoice')
            ->assertOk()
            ->assertJsonFragment(['type' => 'Invoice', 'reference' => $invoice['invoice_number']]);

        $ticketResults = $this->getJson('/api/v1/reference-search?ticket_no=TICKET-778899')
            ->assertOk()
            ->assertJsonFragment(['type' => 'Invoice', 'reference' => $invoice['invoice_number']])
            ->json('data');

        $this->assertFalse(collect($ticketResults)->contains(fn (array $row): bool => $row['type'] === 'Order' && $row['reference'] === $ctx['order']->order_number));

        $this->getJson('/api/v1/reference-search?passenger_phone=03001234567')
            ->assertOk()
            ->assertJsonFragment(['type' => 'Invoice', 'reference' => $invoice['invoice_number']]);

        $this->getJson('/api/v1/reference-search?status=issued')
            ->assertOk()
            ->assertJsonFragment(['type' => 'Invoice', 'reference' => $invoice['invoice_number'], 'status' => 'issued']);

        $ctx['order']->update(['status' => 'cancel']);

        $this->getJson('/api/v1/reference-search?status=cancel')
            ->assertOk()
            ->assertJsonFragment(['type' => 'Invoice', 'reference' => $invoice['invoice_number'], 'status' => 'cancel']);

        $this->getJson('/api/v1/reference-search?q=' . urlencode($ctx['customer']->name))
            ->assertOk()
            ->assertJsonFragment(['type' => 'Customer', 'reference' => $ctx['customer']->name]);
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
        DB::table('invoices')
            ->where('uid', $invoiceUid)
            ->update(['invoice_date' => '2026-08-10 15:45:00']);

        $this->postJson('/api/v1/receipts/customer/record', [
            'invoice_uid' => $invoiceUid,
            'amount' => 250,
            'payment_method' => 'cash',
        ])->assertCreated();
        DB::table('receipts')
            ->where('tenant_id', $ctx['tenant']->id)
            ->update(['receipt_date' => '2026-08-10 16:30:00']);

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

        $sameDayRevenue = $this->getJson('/api/v1/reports/revenue?from_date=2026-08-10&to_date=2026-08-10&group_by=day');
        $sameDayRevenue->assertOk()
            ->assertJsonPath('summary.total_invoices', 1)
            ->assertJsonPath('data.0.period', '2026-08-10');

        $this->getJson('/api/v1/reports/receipts?from_date=2026-08-10&to_date=2026-08-10')
            ->assertOk()
            ->assertJsonPath('summary.total_records', 1);

        $profit = $this->getJson('/api/v1/reports/profit?from_date=2020-01-01&to_date=2030-12-31&group_by=customer');
        $profit->assertOk()
            ->assertJsonPath('filters.group_by', 'customer')
            ->assertJsonPath('summary.total_orders', 1);

        $this->getJson('/api/v1/reports/profit?from_date=2020-01-01&to_date=2030-12-31&group_by=customer&entity_id=' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonPath('filters.entity_id', $ctx['customer']->id)
            ->assertJsonPath('data.0.group_name', 'Test Customer')
            ->assertJsonPath('summary.total_orders', 1);

        $this->getJson('/api/v1/reports/profit?from_date=2020-01-01&to_date=2030-12-31&group_by=staff&entity_id=' . $ctx['user']->id)
            ->assertOk()
            ->assertJsonPath('filters.entity_id', $ctx['user']->id)
            ->assertJsonPath('data.0.group_name', 'API Tester')
            ->assertJsonPath('summary.total_orders', 1);

        $recipient = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $ctx['tenant']->id,
            'company_id' => $ctx['company']->id,
            'role_id' => $ctx['user']->role_id,
            'name' => 'Profit Recipient',
            'email' => 'recipient-' . fake()->unique()->safeEmail(),
            'password' => 'password123',
            'is_active' => true,
        ]);
        $invoice = Invoice::where('uid', $invoiceUid)->firstOrFail();
        $profitShare = ProfitShare::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $ctx['tenant']->id,
            'company_id' => $ctx['company']->id,
            'from_user_id' => $ctx['user']->id,
            'to_user_id' => $recipient->id,
            'invoice_id' => $invoice->id,
            'share_date' => '2026-08-10',
            'currency_code' => 'PKR',
            'amount' => 125,
            'created_by_user_id' => $ctx['user']->id,
            'updated_by_user_id' => $ctx['user']->id,
        ]);
        DB::table('profit_shares')
            ->where('id', $profitShare->id)
            ->update(['share_date' => '2026-08-10 17:15:00']);

        $this->getJson('/api/v1/profit-shares?from_date=2026-08-10&to_date=2026-08-10')
            ->assertOk()
            ->assertJsonPath('data.0.amount', 125);

        $senderProfit = $this->getJson('/api/v1/reports/profit?from_date=2020-01-01&to_date=2030-12-31&group_by=staff&entity_id=' . $ctx['user']->id)
            ->assertOk();
        $senderProfit->assertJsonPath('data.0.shared_out', 125)
            ->assertJsonPath('data.0.shared_in', 0)
            ->assertJsonPath('data.0.profit', 1000)
            ->assertJsonPath('data.0.profit_after_sharing', 875);

        $recipientProfit = $this->getJson('/api/v1/reports/profit?from_date=2020-01-01&to_date=2030-12-31&group_by=staff&entity_id=' . $recipient->id)
            ->assertOk();
        $recipientProfit->assertJsonPath('data.0.group_name', 'Profit Recipient')
            ->assertJsonPath('data.0.shared_out', 0)
            ->assertJsonPath('data.0.shared_in', 125)
            ->assertJsonPath('data.0.profit', 0)
            ->assertJsonPath('data.0.profit_after_sharing', 125);

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

    public function test_order_can_be_duplicated_without_copying_invoice(): void
    {
        $ctx = $this->seedTenantContext();

        $invoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated()->json('invoice');

        $response = $this->postJson('/api/v1/orders/' . $ctx['order']->uid . '/duplicate');
        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('source_order_uid', $ctx['order']->uid)
            ->assertJsonPath('order.status', 'order');

        $duplicatedOrder = Order::where('uid', $response->json('order.uid'))->firstOrFail();
        $this->assertNotSame($ctx['order']->id, $duplicatedOrder->id);
        $this->assertNotSame($ctx['order']->order_number, $duplicatedOrder->order_number);
        $this->assertNull($duplicatedOrder->invoice);
        $this->assertSame(1, $duplicatedOrder->items()->count());
        $this->assertSame((float) $ctx['order']->total_amount, (float) $duplicatedOrder->total_amount);
        $this->assertDatabaseHas('invoices', [
            'uid' => $invoice['uid'],
            'order_id' => $ctx['order']->id,
        ]);
    }

    public function test_refund_order_duplicate_stays_refund_request(): void
    {
        $ctx = $this->seedTenantContext();

        $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated();

        $refundOrder = $this->postJson('/api/v1/orders/' . $ctx['order']->uid . '/refund-request')
            ->assertCreated()
            ->json('order');

        $response = $this->postJson('/api/v1/orders/' . $refundOrder['uid'] . '/duplicate');

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('source_order_uid', $refundOrder['uid'])
            ->assertJsonPath('order.status', 'refund_request')
            ->assertJsonPath('order.total_amount', '-1000.0000');

        $duplicatedOrder = Order::where('uid', $response->json('order.uid'))->firstOrFail();
        $this->assertNotSame($refundOrder['id'], $duplicatedOrder->id);
        $this->assertNull($duplicatedOrder->invoice);
        $this->assertSame(1, $duplicatedOrder->items()->count());
        $this->assertSame(-1000.0, (float) $duplicatedOrder->total_amount);
        $this->assertSame('refund_request', $duplicatedOrder->status);
    }

    public function test_admin_and_accounts_can_change_order_booked_by_from_invoice_folder_action(): void
    {
        $ctx = $this->seedTenantContext();

        $newBooker = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $ctx['tenant']->id,
            'company_id' => $ctx['company']->id,
            'role_id' => $ctx['user']->role_id,
            'name' => 'New Booker',
            'email' => 'booker-' . fake()->unique()->safeEmail(),
            'password' => 'password123',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated();

        $this->patchJson('/api/v1/orders/' . $ctx['order']->uid . '/booked-by', [
            'user_id' => $newBooker->id,
        ])->assertOk()
            ->assertJsonPath('order.created_by.id', $newBooker->id)
            ->assertJsonPath('order.created_by.name', 'New Booker');

        $this->assertDatabaseHas('orders', [
            'id' => $ctx['order']->id,
            'created_by_user_id' => $newBooker->id,
        ]);

        $accountsRole = Role::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $ctx['tenant']->id,
            'code' => 'accounts',
            'name' => 'Accounts',
            'is_system' => false,
        ]);

        $accountsUser = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $ctx['tenant']->id,
            'company_id' => $ctx['company']->id,
            'role_id' => $accountsRole->id,
            'name' => 'Accounts Tester',
            'email' => 'accounts-' . fake()->unique()->safeEmail(),
            'password' => 'password123',
            'is_active' => true,
        ]);

        $secondBooker = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $ctx['tenant']->id,
            'company_id' => $ctx['company']->id,
            'role_id' => $ctx['user']->role_id,
            'name' => 'Accounts Booker',
            'email' => 'accounts-booker-' . fake()->unique()->safeEmail(),
            'password' => 'password123',
            'is_active' => true,
        ]);

        Sanctum::actingAs($accountsUser);

        $this->patchJson('/api/v1/orders/' . $ctx['order']->uid . '/booked-by', [
            'user_id' => $secondBooker->id,
        ])->assertOk()
            ->assertJsonPath('order.created_by.id', $secondBooker->id)
            ->assertJsonPath('order.created_by.name', 'Accounts Booker');

        $this->assertDatabaseHas('orders', [
            'id' => $ctx['order']->id,
            'created_by_user_id' => $secondBooker->id,
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
            ->assertOk()->assertJsonPath('summary.outstanding_balance', 80);

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
                'active_sections' => ['flights', 'visa', 'hotels', 'transfers', 'city_tours', 'other_services'],
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
                'hotels' => [[
                    'vendor_id' => $ctx['vendor']->id,
                    'vendor_name' => $ctx['vendor']->name,
                    'city' => 'Makkah',
                    'hotel_name' => 'Clock Tower',
                    'check_in' => '2026-07-01',
                    'check_out' => '2026-07-05',
                    'cost' => 300,
                    'profit' => 150,
                    'sales' => 450,
                ]],
                'transfers' => [[
                    'vendor_id' => $secondVendor->id,
                    'vendor_name' => $secondVendor->name,
                    'service' => 'Airport transfer',
                    'from_city' => 'Jeddah',
                    'to_city' => 'Makkah',
                    'cost' => 80,
                    'profit' => 40,
                    'sales' => 120,
                ]],
                'city_tours' => [[
                    'vendor_id' => $ctx['vendor']->id,
                    'vendor_name' => $ctx['vendor']->name,
                    'city' => 'Madinah',
                    'title' => 'Ziarat',
                    'date' => '2026-07-06',
                    'cost' => 70,
                    'profit' => 30,
                    'sales' => 100,
                ]],
                'other_services' => [[
                    'vendor_id' => $secondVendor->id,
                    'vendor_name' => $secondVendor->name,
                    'description' => 'Wheelchair assistance',
                    'cost' => 50,
                    'profit' => 25,
                    'sales' => 75,
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
        $this->assertDatabaseHas('order_vendor_costs', [
            'order_id' => $orderId,
            'vendor_id' => $ctx['vendor']->id,
            'service_type' => 'hotel',
            'amount' => 300,
        ]);
        $this->assertDatabaseHas('order_vendor_costs', [
            'order_id' => $orderId,
            'vendor_id' => $secondVendor->id,
            'service_type' => 'transfer',
            'amount' => 80,
        ]);
        $this->assertDatabaseHas('order_vendor_costs', [
            'order_id' => $orderId,
            'vendor_id' => $ctx['vendor']->id,
            'service_type' => 'city_tour',
            'amount' => 70,
        ]);
        $this->assertDatabaseHas('order_vendor_costs', [
            'order_id' => $orderId,
            'vendor_id' => $secondVendor->id,
            'service_type' => 'other_service',
            'amount' => 50,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'total_amount' => 1595,
        ]);

        $this->getJson('/api/v1/orders?search=VCH-SEARCH-991')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $orderId);
        $this->getJson('/api/v1/orders?search=ZXCV12')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $orderId);
        $this->getJson('/api/v1/orders?status=order')
            ->assertOk()
            ->assertJsonFragment(['id' => $orderId]);
        $this->getJson('/api/v1/orders?status=cancel')
            ->assertOk()
            ->assertJsonMissing(['id' => $orderId]);
        $this->getJson('/api/v1/payments/vendor/' . $ctx['vendor']->id . '/payables')
            ->assertOk()
            ->assertJsonMissing(['id' => $orderId]);

        $this->postJson('/api/v1/invoices/create-from-order', ['order_id' => $orderId])->assertCreated();
        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonMissing(['id' => $orderId]);
        $this->getJson('/api/v1/orders?status=invoice')
            ->assertOk()
            ->assertJsonMissing(['id' => $orderId]);
        $this->getJson('/api/v1/payments/vendor/' . $ctx['vendor']->id . '/payables')
            ->assertOk()
            ->assertJsonFragment(['id' => $orderId, 'net_amount' => 770]);
        $this->getJson('/api/v1/payments/vendor/' . $secondVendor->id . '/payables')
            ->assertOk()
            ->assertJsonPath('data.0.net_amount', 430);

        $vendorStatement = $this->getJson('/api/v1/statements/vendor/' . $ctx['vendor']->id)
            ->assertOk();
        $statementOrder = collect($vendorStatement->json('transactions'))
            ->firstWhere('reference', $response->json('order.order_number'));

        $this->assertNotNull($statementOrder);
        $this->assertSame('/orders/' . $response->json('order.uid') . '/edit', $statementOrder['reference_url']);
        $this->assertSame('Test Customer (2 passengers), FLIGHT, HOTEL, TRANSPORT', $statementOrder['description']);
        $this->assertSame(2, $statementOrder['purchase_summary']['passenger_count']);
        $this->assertSame(['FLIGHT', 'HOTEL', 'TRANSPORT'], $statementOrder['purchase_summary']['services']);
        $this->assertCount(2, $statementOrder['purchase_summary']['passenger_details']);
        $this->assertSame('First Passenger', $statementOrder['purchase_summary']['passenger_details'][0]['name']);
        $this->assertSame('1234567890', $statementOrder['purchase_summary']['passenger_details'][0]['ticket_no']);
        $this->assertCount(3, $statementOrder['purchase_summary']['rows']);

        $this->postJson('/api/v1/payments/vendor', [
            'vendor_id' => $ctx['vendor']->id,
            'amount' => 770,
            'payment_method' => 'cash',
            'payment_date' => '2026-06-24',
            'allocations' => [
                ['order_id' => $orderId, 'amount' => 770],
            ],
        ])->assertCreated();

        $this->getJson('/api/v1/payments/vendor/' . $secondVendor->id . '/payables')
            ->assertOk()
            ->assertJsonPath('outstanding_total', 430);

        Order::findOrFail($orderId)->vendorCosts()->delete();
        $this->getJson('/api/v1/reports/profit?from_date=2020-01-01&to_date=2030-12-31&group_by=customer&entity_id=' . $ctx['customer']->id)
            ->assertOk()
            ->assertJsonPath('data.0.revenue', 1595)
            ->assertJsonPath('data.0.cost', 1200)
            ->assertJsonPath('data.0.profit', 395);
    }

    public function test_customer_and_vendor_lists_show_outstanding_except_for_sales_role(): void
    {
        $ctx = $this->seedTenantContext();

        $this->postJson('/api/v1/invoices/create-from-order', ['order_id' => $ctx['order']->id])->assertCreated();

        $customerList = $this->getJson('/api/v1/customers')->assertOk();
        $this->assertSame(1000.0, (float) $customerList->json('0.outstanding_balance'));

        $vendorList = $this->getJson('/api/v1/vendors')->assertOk();
        $this->assertSame(1000.0, (float) $vendorList->json('0.outstanding_balance'));

        $salesRole = Role::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $ctx['tenant']->id,
            'code' => 'sales',
            'name' => 'Sales',
            'is_system' => false,
        ]);

        $salesUser = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $ctx['tenant']->id,
            'company_id' => $ctx['company']->id,
            'role_id' => $salesRole->id,
            'name' => 'Sales Tester',
            'email' => 'sales-' . fake()->unique()->safeEmail(),
            'password' => 'password123',
            'is_active' => true,
        ]);

        Sanctum::actingAs($salesUser);

        $salesCustomers = $this->getJson('/api/v1/customers')->assertOk();
        $this->assertArrayNotHasKey('outstanding_balance', $salesCustomers->json('0'));

        $salesVendors = $this->getJson('/api/v1/vendors')->assertOk();
        $this->assertArrayNotHasKey('outstanding_balance', $salesVendors->json('0'));
    }

    public function test_sales_role_can_view_and_share_invoice_detail_and_voucher_without_payment_rows(): void
    {
        $ctx = $this->seedTenantContext();

        $invoice = $this->postJson('/api/v1/invoices/create-from-order', ['order_id' => $ctx['order']->id])
            ->assertCreated()
            ->json('invoice');
        $this->postJson('/api/v1/receipts/customer/record', [
            'invoice_uid' => $invoice['uid'],
            'amount' => 100,
            'payment_method' => 'cash',
        ])->assertCreated();

        Sanctum::actingAs($this->salesUserForContext($ctx));

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonMissing(['uid' => $ctx['order']->uid]);

        $this->getJson('/api/v1/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.uid', $invoice['uid']);

        $this->getJson('/api/v1/invoices/' . $invoice['uid'])
            ->assertOk()
            ->assertJsonPath('uid', $invoice['uid'])
            ->assertJsonPath('settlements', []);

        $invoiceShare = $this->postJson('/api/v1/invoices/' . $invoice['uid'] . '/share')
            ->assertOk()
            ->assertJsonStructure(['share_url', 'share_token'])
            ->json();
        $this->getJson('/api/v1/shared-invoices/' . $invoiceShare['share_token'])
            ->assertOk()
            ->assertJsonPath('uid', $invoice['uid']);

        $this->getJson('/api/v1/orders/' . $ctx['order']->uid)
            ->assertOk()
            ->assertJsonPath('uid', $ctx['order']->uid);

        $voucherShare = $this->postJson('/api/v1/orders/' . $ctx['order']->uid . '/share')
            ->assertOk()
            ->assertJsonStructure(['share_url', 'share_token'])
            ->json();
        $this->getJson('/api/v1/shared-vouchers/' . $voucherShare['share_token'])
            ->assertOk()
            ->assertJsonPath('uid', $ctx['order']->uid);
    }

    public function test_sales_role_can_view_order_with_legacy_escaped_voucher_meta(): void
    {
        $ctx = $this->seedTenantContext();

        DB::table('orders')
            ->where('id', $ctx['order']->id)
            ->update([
                'meta' => addslashes(json_encode([
                    'pricing' => [[
                        'pax_name' => 'Legacy Passenger',
                        'flight_cost' => '350',
                        'flight_profit' => '150',
                        'flight_sales' => '500',
                    ]],
                    'active_sections' => ['flights'],
                    'flights' => [[
                        'from' => 'KHI',
                        'to' => 'JED',
                        'flight_no' => 'SV705',
                    ]],
                ], JSON_THROW_ON_ERROR)),
            ]);

        Sanctum::actingAs($this->salesUserForContext($ctx));

        $order = $this->getJson('/api/v1/orders/' . $ctx['order']->uid)
            ->assertOk()
            ->assertJsonPath('uid', $ctx['order']->uid)
            ->json();

        $this->assertSame('Legacy Passenger', $order['meta']['pricing'][0]['pax_name']);
        $this->assertSame('500', $order['meta']['pricing'][0]['flight_sales']);
        $this->assertArrayNotHasKey('flight_cost', $order['meta']['pricing'][0]);
        $this->assertArrayNotHasKey('flight_profit', $order['meta']['pricing'][0]);
    }

    public function test_sales_role_can_view_cost_profit_when_company_allows_sales_cost_access(): void
    {
        $ctx = $this->seedTenantContext();
        $ctx['company']->update(['sales_can_edit_cost' => true]);

        DB::table('orders')
            ->where('id', $ctx['order']->id)
            ->update([
                'meta' => addslashes(json_encode([
                    'pricing' => [[
                        'pax_name' => 'Allowed Passenger',
                        'flight_cost' => '350',
                        'flight_profit' => '150',
                        'flight_sales' => '500',
                    ]],
                    'active_sections' => ['flights'],
                    'flights' => [[
                        'from' => 'KHI',
                        'to' => 'JED',
                        'flight_no' => 'SV705',
                    ]],
                ], JSON_THROW_ON_ERROR)),
            ]);

        Sanctum::actingAs($this->salesUserForContext($ctx));

        $order = $this->getJson('/api/v1/orders/' . $ctx['order']->uid)
            ->assertOk()
            ->assertJsonPath('uid', $ctx['order']->uid)
            ->json();

        $this->assertSame('Allowed Passenger', $order['meta']['pricing'][0]['pax_name']);
        $this->assertSame('350', $order['meta']['pricing'][0]['flight_cost']);
        $this->assertSame('150', $order['meta']['pricing'][0]['flight_profit']);
        $this->assertSame('500', $order['meta']['pricing'][0]['flight_sales']);
    }

    public function test_sales_role_can_view_invoice_with_legacy_escaped_order_fields(): void
    {
        $ctx = $this->seedTenantContext();

        $invoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated()->json('invoice');

        DB::table('orders')
            ->where('id', $ctx['order']->id)
            ->update([
                'active_sections' => addslashes(json_encode(['other_services'], JSON_THROW_ON_ERROR)),
                'meta' => addslashes(json_encode([
                    'active_sections' => ['other_services'],
                    'other_services' => [[
                        'description' => 'Legacy Umrah package',
                        'cost' => '250',
                        'profit' => '100',
                        'sales' => '350',
                    ]],
                ], JSON_THROW_ON_ERROR)),
            ]);

        Sanctum::actingAs($this->salesUserForContext($ctx));

        $invoiceDetail = $this->getJson('/api/v1/invoices/' . $invoice['uid'])
            ->assertOk()
            ->assertJsonPath('uid', $invoice['uid'])
            ->assertJsonPath('settlements', [])
            ->json();

        $this->assertSame(['other_services'], $invoiceDetail['order']['active_sections']);
        $this->assertSame('Legacy Umrah package', $invoiceDetail['order']['meta']['other_services'][0]['description']);
        $this->assertSame('350', $invoiceDetail['order']['meta']['other_services'][0]['sales']);
        $this->assertArrayNotHasKey('cost', $invoiceDetail['order']['meta']['other_services'][0]);
        $this->assertArrayNotHasKey('profit', $invoiceDetail['order']['meta']['other_services'][0]);
    }

    public function test_order_and_invoice_responses_normalize_escaped_contact_text(): void
    {
        $ctx = $this->seedTenantContext();
        $escapedAddress = 'Shop no 25, Azizabad Main Rd, Federal B Area Block 9 \\r\\nGulshan e Shamim, Karachi, 75950, Pakistan\\r\\n+92 21 33512473 /  +92 336 3819460';

        $ctx['company']->update(['address' => $escapedAddress]);
        $ctx['customer']->update([
            'address' => $escapedAddress,
            'phone' => '+92336031901',
            'email' => 'abdullah@rihlatravelandtours.com',
        ]);
        $ctx['order']->update([
            'meta' => [
                'contact' => [
                    'address' => $escapedAddress,
                    'phone' => '+92336031901',
                    'email' => 'abdullah@rihlatravelandtours.com',
                ],
            ],
        ]);

        $expectedAddress = "Shop no 25, Azizabad Main Rd, Federal B Area Block 9\nGulshan e Shamim, Karachi, 75950, Pakistan\n+92 21 33512473 / +92 336 3819460";

        $this->getJson('/api/v1/orders/' . $ctx['order']->uid)
            ->assertOk()
            ->assertJsonPath('company.address', $expectedAddress)
            ->assertJsonPath('customer.address', $expectedAddress)
            ->assertJsonPath('meta.contact.address', $expectedAddress);

        $invoice = $this->postJson('/api/v1/invoices/create-from-order', [
            'order_id' => $ctx['order']->id,
        ])->assertCreated()->json('invoice');

        $this->getJson('/api/v1/invoices/' . $invoice['uid'])
            ->assertOk()
            ->assertJsonPath('company.address', $expectedAddress)
            ->assertJsonPath('customer.address', $expectedAddress)
            ->assertJsonPath('order.meta.contact.address', $expectedAddress);
    }

    public function test_sales_role_cannot_view_or_update_receipts_and_payments(): void
    {
        $ctx = $this->seedTenantContext();

        $invoice = $this->postJson('/api/v1/invoices/create-from-order', ['order_id' => $ctx['order']->id])
            ->assertCreated()
            ->json('invoice');
        $this->postJson('/api/v1/receipts/customer/record', [
            'invoice_uid' => $invoice['uid'],
            'amount' => 100,
            'payment_method' => 'cash',
        ])->assertCreated();
        $customerReceipt = Receipt::where('customer_id', $ctx['customer']->id)->firstOrFail();
        $customerPayment = $this->postJson('/api/v1/payments/customer', [
            'customer_id' => $ctx['customer']->id,
            'amount' => 25,
            'payment_method' => 'cash',
        ])->assertCreated()->json('payment');
        $vendorPayment = $this->postJson('/api/v1/payments/vendor', [
            'vendor_id' => $ctx['vendor']->id,
            'amount' => 50,
            'payment_method' => 'cash',
        ])->assertCreated()->json('payment');
        $vendorReceipt = $this->postJson('/api/v1/receipts/vendor', [
            'vendor_id' => $ctx['vendor']->id,
            'amount' => 30,
            'payment_method' => 'cash',
        ])->assertCreated()->json('receipt');

        Sanctum::actingAs($this->salesUserForContext($ctx));

        $this->getJson('/api/v1/receipts/customer')->assertForbidden();
        $this->getJson('/api/v1/receipts/customer/' . $customerReceipt->uid)->assertForbidden();
        $this->getJson('/api/v1/receipts/vendor')->assertForbidden();
        $this->getJson('/api/v1/payments/customer')->assertForbidden();
        $this->getJson('/api/v1/payments/vendor')->assertForbidden();
        $this->getJson('/api/v1/payments/vendor/payment/' . $vendorPayment['uid'])->assertForbidden();
        $this->getJson('/api/v1/payments/vendor/' . $ctx['vendor']->id . '/payables')->assertForbidden();
        $this->getJson('/api/v1/payments/invoices/' . $invoice['uid'] . '/settlements')->assertForbidden();

        $this->patchJson('/api/v1/receipts/customer/' . $customerReceipt->uid, [])->assertForbidden();
        $this->patchJson('/api/v1/receipts/vendor/' . $vendorReceipt['uid'], [])->assertForbidden();
        $this->patchJson('/api/v1/payments/customer/' . $customerPayment['uid'], [])->assertForbidden();
        $this->patchJson('/api/v1/payments/vendor/payment/' . $vendorPayment['uid'], [])->assertForbidden();
        $this->postJson('/api/v1/receipts/customer/record', [])->assertForbidden();
        $this->postJson('/api/v1/payments/customer', [])->assertForbidden();
    }

    public function test_sales_can_create_receivings_accounts_can_clear_and_admin_can_delete_them(): void
    {
        $ctx = $this->seedTenantContext();
        $sales = $this->salesUserForContext($ctx);

        Sanctum::actingAs($sales);

        $created = $this->postJson('/api/v1/receivings', [
            'amount' => 123.45,
            'paid_by' => 'Walk-in Customer',
            'reference_customer_id' => $ctx['customer']->id,
            'notes' => 'Initial counter note',
        ])->assertCreated()
            ->assertJsonPath('receiving.receiving_number', 'REC0001')
            ->assertJsonPath('receiving.status', 'received')
            ->assertJsonPath('receiving.paid_by', 'Walk-in Customer')
            ->assertJsonPath('receiving.notes', 'Initial counter note')
            ->assertJsonPath('receiving.received_by.name', 'Sales Tester')
            ->json('receiving');

        $this->getJson('/api/v1/receivings')
            ->assertOk()
            ->assertJsonPath('data.0.uid', $created['uid'])
            ->assertJsonPath('data.0.reference_customer.name', 'Test Customer');

        $this->patchJson('/api/v1/receivings/' . $created['uid'], [
            'amount' => 200,
            'paid_by' => 'Edited by sales',
            'notes' => 'Sales edit should fail',
        ])->assertForbidden();
        $this->deleteJson('/api/v1/receivings/' . $created['uid'])->assertForbidden();

        $accountsRole = Role::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $ctx['tenant']->id,
            'code' => 'accounts',
            'name' => 'Accounts',
            'is_system' => false,
        ]);
        $accountsUser = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $ctx['tenant']->id,
            'company_id' => $ctx['company']->id,
            'role_id' => $accountsRole->id,
            'name' => 'Accounts Tester',
            'email' => 'receiving-accounts-' . fake()->unique()->safeEmail(),
            'password' => 'password123',
            'is_active' => true,
        ]);

        Sanctum::actingAs($accountsUser);

        $this->getJson('/api/v1/receivings')
            ->assertOk()
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('can_delete', false);

        $this->patchJson('/api/v1/receivings/' . $created['uid'], [
            'amount' => 200,
            'status' => 'clear',
            'paid_by' => 'Cleared by accounts',
            'reference_customer_id' => null,
            'notes' => 'Accounts cleared note',
        ])->assertOk()
            ->assertJsonPath('receiving.amount', '200.0000')
            ->assertJsonPath('receiving.status', 'clear')
            ->assertJsonPath('receiving.paid_by', 'Cleared by accounts')
            ->assertJsonPath('receiving.notes', 'Accounts cleared note')
            ->assertJsonPath('receiving.updated_by.name', 'Accounts Tester');

        $this->getJson('/api/v1/receivings/' . $created['uid'])
            ->assertOk()
            ->assertJsonPath('receiving_number', 'REC0001')
            ->assertJsonPath('company.display_name', 'API Test Company');

        $this->deleteJson('/api/v1/receivings/' . $created['uid'])->assertForbidden();

        Sanctum::actingAs($ctx['user']);

        $this->patchJson('/api/v1/receivings/' . $created['uid'], [
            'amount' => 200,
            'status' => 'clear',
            'paid_by' => 'Edited by admin',
            'reference_customer_id' => null,
            'notes' => 'Admin adjusted note',
        ])->assertOk()
            ->assertJsonPath('receiving.amount', '200.0000')
            ->assertJsonPath('receiving.status', 'clear')
            ->assertJsonPath('receiving.paid_by', 'Edited by admin')
            ->assertJsonPath('receiving.notes', 'Admin adjusted note');

        $this->deleteJson('/api/v1/receivings/' . $created['uid'])->assertOk();

        $receiving = Receiving::withTrashed()->where('uid', $created['uid'])->firstOrFail();
        $this->assertTrue($receiving->trashed());
        $this->assertSame($ctx['user']->id, $receiving->deleted_by_user_id);

        $this->getJson('/api/v1/receivings')
            ->assertOk()
            ->assertJsonPath('data.0.uid', $created['uid'])
            ->assertJsonPath('data.0.is_deleted', true);

        $this->assertDatabaseHas('audit_logs', [
            'model_type' => Receiving::class,
            'model_id' => $receiving->id,
            'action' => 'receiving_created',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => Receiving::class,
            'model_id' => $receiving->id,
            'action' => 'receiving_updated',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => Receiving::class,
            'model_id' => $receiving->id,
            'action' => 'receiving_deleted',
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

    private function salesUserForContext(array $ctx): User
    {
        $salesRole = Role::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $ctx['tenant']->id,
            'code' => 'sales',
            'name' => 'Sales',
            'is_system' => false,
        ]);

        return User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $ctx['tenant']->id,
            'company_id' => $ctx['company']->id,
            'role_id' => $salesRole->id,
            'name' => 'Sales Tester',
            'email' => 'sales-' . fake()->unique()->safeEmail(),
            'password' => 'password123',
            'is_active' => true,
        ]);
    }
}
