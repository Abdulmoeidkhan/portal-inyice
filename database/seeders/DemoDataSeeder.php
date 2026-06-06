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

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // Create tenant
        $tenant = Tenant::create([
            'code' => 'demo001',
            'name' => 'Demo Travel Agency',
            'is_active' => true,
        ]);

        // Create company
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Agency Limited',
            'email' => 'info@demoagency.com',
            'phone' => '+92-300-1234567',
            'base_currency_code' => 'PKR',
            'timezone' => 'Asia/Karachi',
        ]);

        // Create roles
        $adminRole = Role::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'description' => 'Full system access',
        ]);

        $salesRole = Role::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sales',
            'description' => 'Can create orders and invoices',
        ]);

        $accountsRole = Role::create([
            'tenant_id' => $tenant->id,
            'name' => 'Accounts',
            'description' => 'Can manage invoices and payments',
        ]);

        // Create users
        $adminUser = User::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'role_id' => $adminRole->id,
            'name' => 'Admin User',
            'email' => 'admin@demoagency.com',
            'password' => 'password123',
        ]);

        $salesUser = User::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'role_id' => $salesRole->id,
            'name' => 'Sales User',
            'email' => 'sales@demoagency.com',
            'password' => 'password123',
        ]);

        // Create customers
        $customer1 = Customer::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => 'B2C',
            'name' => 'John Smith',
            'email' => 'john.smith@email.com',
            'phone' => '+92-300-1111111',
            'billing_address' => 'Karachi, Pakistan',
        ]);

        $customer2 = Customer::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => 'B2C',
            'name' => 'ABC Travel Co',
            'email' => 'info@abctravel.com',
            'phone' => '+92-21-1234567',
            'billing_address' => 'Islamabad, Pakistan',
        ]);

        // Create vendors
        $vendor1 = Vendor::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => 'B2B',
            'name' => 'XYZ Airlines',
            'email' => 'booking@xyzairlines.com',
            'phone' => '+1-800-555-1234',
            'billing_address' => 'Dubai, UAE',
            'payment_terms' => '30 days',
        ]);

        // Create cash account
        CashAccount::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'account_code' => 'CA-001',
            'account_name' => 'Main Cash Box',
            'currency_code' => 'PKR',
            'opening_balance' => 50000,
            'current_balance' => 50000,
        ]);

        // Create bank account
        BankAccount::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'bank_name' => 'National Bank of Pakistan',
            'account_number' => '1234567890',
            'account_holder' => 'Demo Agency Limited',
            'currency_code' => 'PKR',
            'opening_balance' => 500000,
            'current_balance' => 500000,
        ]);

        // Create orders
        for ($i = 1; $i <= 5; $i++) {
            $order = Order::create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'customer_id' => $i % 2 == 0 ? $customer1->id : $customer2->id,
                'order_number' => app(\App\Services\OrderNumberService::class)->generateOrderNumber($company->id),
                'order_date' => now()->subDays($i * 5)->toDateString(),
                'status' => $i % 3 == 0 ? 'quote' : 'order',
                'total_amount' => 50000 + ($i * 5000),
            ]);

            // Add items to order
            OrderItem::create([
                'tenant_id' => $tenant->id,
                'order_id' => $order->id,
                'description' => 'Flight Booking - Karachi to Dubai',
                'quantity' => 2,
                'unit_price' => 15000,
                'total_price' => 30000,
            ]);

            OrderItem::create([
                'tenant_id' => $tenant->id,
                'order_id' => $order->id,
                'description' => 'Hotel Accommodation - 5 nights',
                'quantity' => 5,
                'unit_price' => 4000,
                'total_price' => 20000,
            ]);
        }

        $this->command->info('Demo data seeded successfully!');
    }
}
