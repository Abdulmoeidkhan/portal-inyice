<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->string('share_token', 64)->nullable()->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('invoice_number', 50);
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->char('currency_code', 3);
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->decimal('outstanding_amount', 18, 4)->default(0);
            $table->decimal('advance_balance', 18, 4)->default(0);
            $table->enum('status', ['draft', 'issued', 'sent', 'partial_paid', 'paid', 'overdue', 'void'])->default('draft');
            $table->decimal('fx_rate_to_base', 18, 8)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('company_id');
            $table->index('order_id');
            $table->index('customer_id');
            $table->index('invoice_date');
            $table->index('status');
            $table->index(['tenant_id', 'company_id', 'status', 'invoice_date', 'id'], 'invoices_tenant_company_status_date_id_index');
            $table->index(['tenant_id', 'company_id', 'customer_id', 'invoice_date', 'id'], 'invoices_tenant_company_customer_date_id_index');
            $table->unique(['tenant_id', 'uid']);
            $table->unique(['company_id', 'invoice_number']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('restrict');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('restrict');
            $table->foreign('currency_code')->references('code')->on('currencies')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
