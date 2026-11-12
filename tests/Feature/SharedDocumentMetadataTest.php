<?php

namespace Tests\Feature;

use App\Models\{Company, Customer, Invoice, Order, Role, Tenant, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedDocumentMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_shared_document_variants_render_metadata_without_javascript(): void
    {
        [$company, $order, $invoice] = $this->documents();
        foreach ([
            ['/shared/vouchers/'.$order->share_token, 'Travel Voucher RT-140'],
            ['/shared/itineraries/'.$order->share_token, 'Travel Itinerary RT-140'],
            ['/shared/quotations/'.$order->share_token, 'Quotation RT-140'],
            ['/shared/invoices/'.$invoice->share_token, 'Invoice INV-140'],
            ['/shared/invoices/'.$invoice->share_token.'?view=detailed', 'Detailed Invoice INV-140'],
        ] as [$path, $title]) {
            $this->get($path)->assertOk()
                ->assertSee('property="og:title" content="'.$title.' | Demo Travel"', false)
                ->assertSee('property="og:site_name" content="Demo Travel"', false)
                ->assertSee('/storage/agency-logo.png', false)
                ->assertSee('name="twitter:card"', false)
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
                ->assertDontSee('PRIVATE PASSENGER')->assertDontSee('INTERNAL NOTE');
        }
        $this->get('/shared/invoices/'.$invoice->share_token.'?view=detailed&ignored=secret')
            ->assertSee('?view=detailed', false)->assertDontSee('ignored=secret')
            ->assertSee('PKR 4,462.50')->assertSee('2026-10-13');
        $this->get('/shared/vouchers/'.$order->share_token)->assertSee('Umrah Package');
    }

    public function test_unpaid_branding_and_revoked_tokens(): void
    {
        [$company, $order, $invoice] = $this->documents();
        $company->update(['is_paid' => false]);
        $this->get('/shared/invoices/'.$invoice->share_token)->assertOk()
            ->assertSee('property="og:site_name" content="InYice"', false)
            ->assertSee('/images/icons/icon-512x512.png', false)
            ->assertDontSee('Demo Travel')->assertDontSee('agency-logo.png');
        $path = '/shared/vouchers/'.$order->share_token;
        $order->update(['share_token' => null]);
        $this->get($path)->assertNotFound()->assertDontSee('RT-140');
        $this->get('/shared/itineraries/'.substr($path, strrpos($path, '/') + 1))->assertNotFound();
        $this->get('/shared/invoices/not-a-valid-token')->assertNotFound();
        $this->get('/shared/invoices/'.$invoice->uid)->assertNotFound();
    }

    public function test_metadata_is_escaped_and_paid_accounts_without_logos_have_a_fallback(): void
    {
        [$company, $order] = $this->documents();
        $company->update(['display_name' => 'Demo " onload="alert(1)', 'logo_path' => null]);
        $this->get('/shared/vouchers/'.$order->share_token)->assertOk()
            ->assertSee('Demo &quot; onload=&quot;alert(1)', false)
            ->assertDontSee('content="Demo " onload=', false)
            ->assertSee('/images/icons/icon-512x512.png', false);
    }

    private function documents(): array
    {
        $this->withoutVite();
        Notification::fake();
        $tenant = Tenant::create(['uid' => (string) Str::ulid(), 'code' => 'og-test', 'name' => 'OG test', 'is_active' => true]);
        $company = Company::create(['uid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'legal_name' => 'Demo Travel', 'display_name' => 'Demo Travel', 'base_currency_code' => 'PKR', 'default_timezone' => 'Asia/Karachi', 'is_paid' => true, 'is_active' => true, 'logo_path' => 'agency-logo.png']);
        $role = Role::create(['uid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'code' => 'owner', 'name' => 'Owner']);
        $user = User::create(['uid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'role_id' => $role->id, 'name' => 'Owner', 'email' => 'og@example.com', 'password' => 'test-password', 'is_active' => true]);
        $customer = Customer::create(['uid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'type' => 'B2C', 'name' => 'PRIVATE PASSENGER', 'currency_code' => 'PKR']);
        $order = Order::create(['uid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'customer_id' => $customer->id, 'created_by_user_id' => $user->id, 'updated_by_user_id' => $user->id, 'order_number' => 'ORD-140', 'share_token' => Str::random(64), 'status' => 'order', 'currency_code' => 'PKR', 'total_amount' => 4462.5, 'notes' => 'INTERNAL NOTE', 'meta' => ['voucher_no' => 'RT-140', 'issue_date' => '2026-10-13', 'package_type' => 'Umrah Package']]);
        $invoice = Invoice::create(['uid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'customer_id' => $customer->id, 'order_id' => $order->id, 'invoice_number' => 'INV-140', 'invoice_date' => '2026-10-13', 'currency_code' => 'PKR', 'total_amount' => 4462.5, 'status' => 'issued', 'share_token' => Str::random(64)]);

        return [$company, $order, $invoice];
    }
}
