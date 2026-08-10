<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profit_shares', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('from_user_id');
            $table->unsignedBigInteger('to_user_id');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->date('share_date');
            $table->char('currency_code', 3);
            $table->decimal('amount', 18, 4);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('company_id');
            $table->index('from_user_id');
            $table->index('to_user_id');
            $table->index('invoice_id');
            $table->index('share_date');
            $table->index(['tenant_id', 'company_id', 'share_date', 'id'], 'profit_shares_tenant_company_date_id_index');
            $table->unique(['tenant_id', 'uid']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('from_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('to_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('currency_code')->references('code')->on('currencies')->onDelete('restrict');
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profit_shares');
    }
};
