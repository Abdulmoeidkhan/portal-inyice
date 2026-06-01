<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('vendor_id');
            $table->string('payment_number', 50)->unique();
            $table->date('payment_date');
            $table->decimal('amount', 18, 4);
            $table->char('currency_code', 3);
            $table->enum('payment_method', ['cash', 'bank_transfer', 'check', 'card'])->default('bank_transfer');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->enum('account_type', ['cash', 'bank'])->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('company_id');
            $table->index('vendor_id');
            $table->index('payment_date');
            $table->unique(['tenant_id', 'uid']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('restrict');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('currency_code')->references('code')->on('currencies')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
