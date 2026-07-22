<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE invoices MODIFY status ENUM('draft','issued','sent','partial_paid','paid','overdue','void','cancel') NOT NULL DEFAULT 'draft'");
        } elseif (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteInvoicesTable(true);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::table('invoices')
                ->where('status', 'cancel')
                ->update(['status' => 'void']);

            DB::statement("ALTER TABLE invoices MODIFY status ENUM('draft','issued','sent','partial_paid','paid','overdue','void') NOT NULL DEFAULT 'draft'");
        } elseif (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::table('invoices')
                ->where('status', 'cancel')
                ->update(['status' => 'void']);

            $this->rebuildSqliteInvoicesTable(false);
        }
    }

    private function rebuildSqliteInvoicesTable(bool $includeCancel): void
    {
        $statuses = "'draft', 'issued', 'sent', 'partial_paid', 'paid', 'overdue', 'void'"
            . ($includeCancel ? ", 'cancel'" : '');

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('PRAGMA legacy_alter_table = ON');
        DB::statement('ALTER TABLE invoices RENAME TO invoices_old');
        DB::statement("
            CREATE TABLE invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                uid VARCHAR(26) NOT NULL,
                share_token VARCHAR(64) DEFAULT NULL,
                tenant_id INTEGER UNSIGNED NOT NULL,
                company_id INTEGER UNSIGNED NOT NULL,
                order_id INTEGER UNSIGNED NOT NULL,
                customer_id INTEGER UNSIGNED NOT NULL,
                invoice_number VARCHAR(50) NOT NULL,
                invoice_date DATE NOT NULL,
                due_date DATE DEFAULT NULL,
                currency_code VARCHAR(3) NOT NULL,
                subtotal NUMERIC NOT NULL DEFAULT '0',
                tax_amount NUMERIC NOT NULL DEFAULT '0',
                total_amount NUMERIC NOT NULL DEFAULT '0',
                outstanding_amount NUMERIC NOT NULL DEFAULT '0',
                advance_balance NUMERIC NOT NULL DEFAULT '0',
                status VARCHAR CHECK (status IN ({$statuses})) NOT NULL DEFAULT 'draft',
                fx_rate_to_base NUMERIC DEFAULT NULL,
                notes CLOB DEFAULT NULL,
                created_at DATETIME DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE,
                FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT,
                FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
                FOREIGN KEY(currency_code) REFERENCES currencies(code) ON DELETE RESTRICT
            )
        ");
        DB::statement("
            INSERT INTO invoices (
                id, uid, share_token, tenant_id, company_id, order_id, customer_id, invoice_number,
                invoice_date, due_date, currency_code, subtotal, tax_amount, total_amount,
                outstanding_amount, advance_balance, status, fx_rate_to_base, notes, created_at, updated_at
            )
            SELECT
                id, uid, share_token, tenant_id, company_id, order_id, customer_id, invoice_number,
                invoice_date, due_date, currency_code, subtotal, tax_amount, total_amount,
                outstanding_amount, advance_balance, status, fx_rate_to_base, notes, created_at, updated_at
            FROM invoices_old
        ");
        DB::statement('DROP TABLE invoices_old');
        DB::statement('CREATE UNIQUE INDEX invoices_uid_unique ON invoices (uid)');
        DB::statement('CREATE UNIQUE INDEX invoices_share_token_unique ON invoices (share_token)');
        DB::statement('CREATE INDEX invoices_tenant_id_index ON invoices (tenant_id)');
        DB::statement('CREATE INDEX invoices_company_id_index ON invoices (company_id)');
        DB::statement('CREATE INDEX invoices_order_id_index ON invoices (order_id)');
        DB::statement('CREATE INDEX invoices_customer_id_index ON invoices (customer_id)');
        DB::statement('CREATE INDEX invoices_invoice_date_index ON invoices (invoice_date)');
        DB::statement('CREATE INDEX invoices_status_index ON invoices (status)');
        DB::statement('CREATE UNIQUE INDEX invoices_tenant_id_uid_unique ON invoices (tenant_id, uid)');
        DB::statement('CREATE UNIQUE INDEX invoices_company_id_invoice_number_unique ON invoices (company_id, invoice_number)');
        DB::statement('PRAGMA legacy_alter_table = OFF');
        DB::statement('PRAGMA foreign_keys = ON');
    }
};
