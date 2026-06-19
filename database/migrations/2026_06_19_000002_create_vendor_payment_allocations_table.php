<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('order_id');
            $table->decimal('amount', 18, 4);
            $table->timestamps();

            $table->unique(['payment_id', 'order_id']);
            $table->index(['tenant_id', 'order_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('cascade');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payment_allocations');
    }
};
