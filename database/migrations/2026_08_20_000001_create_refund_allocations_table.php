<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('receipt_id')->nullable();
            $table->enum('allocation_type', ['customer_payment', 'vendor_receipt']);
            $table->decimal('amount', 18, 4);
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'allocation_type']);
            $table->unique(['payment_id', 'order_id'], 'refund_allocations_payment_order_unique');
            $table->unique(['receipt_id', 'order_id'], 'refund_allocations_receipt_order_unique');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('restrict');
            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('cascade');
            $table->foreign('receipt_id')->references('id')->on('receipts')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_allocations');
    }
};
