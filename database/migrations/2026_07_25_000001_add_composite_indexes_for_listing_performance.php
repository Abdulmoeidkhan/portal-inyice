<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $indexes = [
        'orders' => [
            'orders_tenant_company_status_id_index' => ['tenant_id', 'company_id', 'status', 'id'],
            'orders_tenant_company_updated_id_index' => ['tenant_id', 'company_id', 'updated_at', 'id'],
        ],
        'invoices' => [
            'invoices_tenant_company_status_date_id_index' => ['tenant_id', 'company_id', 'status', 'invoice_date', 'id'],
            'invoices_tenant_company_customer_date_id_index' => ['tenant_id', 'company_id', 'customer_id', 'invoice_date', 'id'],
        ],
        'payments' => [
            'payments_tenant_company_date_id_index' => ['tenant_id', 'company_id', 'payment_date', 'id'],
        ],
        'receipts' => [
            'receipts_tenant_company_date_id_index' => ['tenant_id', 'company_id', 'receipt_date', 'id'],
        ],
        'ledger_entries' => [
            'ledger_entries_tenant_account_date_id_index' => ['tenant_id', 'account_type', 'account_id', 'entry_date', 'id'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $tableName => $indexes) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $indexes): void {
                foreach ($indexes as $indexName => $columns) {
                    if (! Schema::hasIndex($tableName, $indexName)) {
                        $table->index($columns, $indexName);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $tableName => $indexes) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $indexes): void {
                foreach (array_keys($indexes) as $indexName) {
                    if (Schema::hasIndex($tableName, $indexName)) {
                        $table->dropIndex($indexName);
                    }
                }
            });
        }
    }
};
