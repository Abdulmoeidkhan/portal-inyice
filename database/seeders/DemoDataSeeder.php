<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\Customer;
use App\Models\Vendor;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\CashAccount;
use App\Models\BankAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['code' => 'demo001'],
            [
                'uid' => (string) Str::ulid(),
                'name' => 'Demo Travel Agency',
                'is_active' => true,
            ]
        );

        $company = Company::firstOrCreate(
            ['tenant_id' => $tenant->id, 'display_name' => 'Demo Agency Limited'],
            [
                'uid' => (string) Str::ulid(),
                'legal_name' => 'Demo Agency Limited',
                'email' => 'info@demoagency.com',
                'phone' => '+92-300-1234567',
                'address' => 'Karachi, Pakistan',
                'country_code' => 'PK',
                'base_currency_code' => 'PKR',
                'default_timezone' => 'Asia/Karachi',
                'is_active' => true,
            ]
        );

        $roles = [];

        foreach (Role::TENANT_DEFAULT_ROLES as $roleDefaults) {
            $roles[$roleDefaults['code']] = Role::firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $roleDefaults['code']],
                [
                    'uid' => (string) Str::ulid(),
                    'name' => $roleDefaults['name'],
                    'is_system' => false,
                ]
            );
        }

        $ownerUser = User::updateOrCreate(
            ['email' => 'admin@demoagency.com'],
            [
                'uid' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'role_id' => $roles[Role::SIGNUP_DEFAULT_ROLE]->id,
                'name' => 'Owner User',
                'password' => 'password123',
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'sales@demoagency.com'],
            [
                'uid' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'role_id' => $roles['sales']->id,
                'name' => 'Sales User',
                'password' => 'password123',
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );

        $customer1 = Customer::updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => 'john.smith@email.com'],
            [
                'uid' => (string) Str::ulid(),
                'company_id' => $company->id,
                'type' => 'B2C',
                'name' => 'John Smith',
                'phone' => '+92-300-1111111',
                'address' => 'Karachi, Pakistan',
                'city' => 'Karachi',
                'country_code' => 'PK',
                'currency_code' => 'PKR',
                'is_active' => true,
            ]
        );

        $customer2 = Customer::updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => 'info@abctravel.com'],
            [
                'uid' => (string) Str::ulid(),
                'company_id' => $company->id,
                'type' => 'B2B',
                'name' => 'ABC Travel Co',
                'phone' => '+92-21-1234567',
                'address' => 'Islamabad, Pakistan',
                'city' => 'Islamabad',
                'country_code' => 'PK',
                'currency_code' => 'PKR',
                'is_active' => true,
            ]
        );

        $vendor1 = Vendor::updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => 'booking@xyzairlines.com'],
            [
                'uid' => (string) Str::ulid(),
                'company_id' => $company->id,
                'type' => 'B2B',
                'name' => 'XYZ Airlines',
                'phone' => '+1-800-555-1234',
                'address' => 'Dubai, UAE',
                'city' => 'Dubai',
                'country_code' => 'AE',
                'currency_code' => 'AED',
                'payment_terms' => '30 days',
                'is_active' => true,
            ]
        );

        CashAccount::updateOrCreate(
            ['account_code' => 'CA-DEMO-001'],
            [
                'uid' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'account_name' => 'Main Cash Box',
                'currency_code' => 'PKR',
                'opening_balance' => 50000,
                'current_balance' => 50000,
                'is_active' => true,
            ]
        );

        BankAccount::updateOrCreate(
            ['account_number' => '1234567890'],
            [
                'uid' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'bank_name' => 'National Bank of Pakistan',
                'account_holder' => 'Demo Agency Limited',
                'currency_code' => 'PKR',
                'opening_balance' => 500000,
                'current_balance' => 500000,
                'is_active' => true,
            ]
        );

        for ($i = 1; $i <= 5; $i++) {
            $flightAmount = 30000;
            $hotelAmount = 20000;
            $otherServiceAmount = $i * 5000;
            $totalAmount = $flightAmount + $hotelAmount + $otherServiceAmount;

            $order = Order::firstOrNew(['tenant_id' => $tenant->id, 'booking_reference' => 'DEMO-PNR-' . $i]);

            if (!$order->exists) {
                $order->uid = (string) Str::ulid();
                $order->order_number = app(\App\Services\OrderNumberService::class)->generateOrderNumber($company->id, $tenant->id);
            }

            $order->fill([
                'company_id' => $company->id,
                'customer_id' => $i % 2 === 0 ? $customer1->id : $customer2->id,
                'vendor_id' => $vendor1->id,
                'created_by_user_id' => $ownerUser->id,
                'updated_by_user_id' => $ownerUser->id,
                'status' => $i % 3 === 0 ? 'quote' : 'order',
                'currency_code' => 'PKR',
                'total_amount' => $totalAmount,
                'notes' => 'Demo order seeded for testing.',
                'active_sections' => ['flights', 'hotels', 'other_services'],
                'meta' => [
                    'issue_date' => now()->subDays($i * 5)->toDateString(),
                    'voucher_no' => 'DEMO-VCH-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                    'active_sections' => ['flights', 'hotels', 'other_services'],
                    'flights' => [[
                        'flight_no' => 'XY' . (380 + $i),
                        'date' => now()->addDays($i * 3)->toDateString(),
                        'from' => 'KHI',
                        'to' => 'DXB',
                        'departure' => '10:00',
                        'arrival' => '12:30',
                        'cabin' => 'economy',
                        'booking_class' => 'Y',
                        'gds_pnr' => 'DEMO-PNR-' . $i,
                        'pnr' => 'AIR-PNR-' . $i,
                    ]],
                    'passengers' => [[
                        'name' => $i % 2 === 0 ? 'John Smith' : 'ABC Travel Guest',
                        'ticket_no' => '157000' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    ]],
                    'pricing' => [[
                        'pax_name' => $i % 2 === 0 ? 'John Smith' : 'ABC Travel Guest',
                        'flight_ticket_no' => '157000' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                        'vendor_id' => $vendor1->id,
                        'vendor_name' => $vendor1->name,
                        'flight_cost' => 25000,
                        'flight_profit' => 5000,
                        'flight_sales' => $flightAmount,
                    ]],
                    'hotels' => [[
                        'city' => 'Dubai',
                        'hotel_name' => 'Demo City Hotel',
                        'room_type' => 'Standard',
                        'check_in' => now()->addDays($i * 3)->toDateString(),
                        'check_out' => now()->addDays(($i * 3) + 5)->toDateString(),
                        'lead_passenger' => $i % 2 === 0 ? 'John Smith' : 'ABC Travel Guest',
                        'amount' => $hotelAmount,
                        'notes' => 'Demo hotel accommodation.',
                    ]],
                    'other_services' => [[
                        'description' => 'Miscellaneous seeded service amount',
                        'amount' => $otherServiceAmount,
                    ]],
                ],
            ]);
            $order->save();

            OrderItem::updateOrCreate(
                ['tenant_id' => $tenant->id, 'order_id' => $order->id, 'description' => 'Flight Booking - Karachi to Dubai'],
                [
                    'uid' => (string) Str::ulid(),
                    'quantity' => 2,
                    'unit_price' => $flightAmount / 2,
                    'total_price' => $flightAmount,
                ]
            );

            OrderItem::updateOrCreate(
                ['tenant_id' => $tenant->id, 'order_id' => $order->id, 'description' => 'Hotel Accommodation - 5 nights'],
                [
                    'uid' => (string) Str::ulid(),
                    'quantity' => 5,
                    'unit_price' => $hotelAmount / 5,
                    'total_price' => $hotelAmount,
                ]
            );

            OrderItem::updateOrCreate(
                ['tenant_id' => $tenant->id, 'order_id' => $order->id, 'description' => 'Other Service - Miscellaneous seeded service amount'],
                [
                    'uid' => (string) Str::ulid(),
                    'quantity' => 1,
                    'unit_price' => $otherServiceAmount,
                    'total_price' => $otherServiceAmount,
                ]
            );
        }

        $this->command->info('Demo data seeded successfully!');
    }
}
