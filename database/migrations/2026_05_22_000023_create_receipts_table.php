<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('receipt_number', 50)->unique();
            $table->date('receipt_date');
            $table->decimal('amount', 18, 4);
            $table->char('currency_code', 3);
            $table->enum('payment_method', ['cash', 'bank_transfer', 'check', 'card'])->default('cash');
            $table->string('reference_number', 100)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('company_id');
            $table->index('customer_id');
            $table->index('receipt_date');
            $table->unique(['tenant_id', 'uid']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('restrict');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('currency_code')->references('code')->on('currencies')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
