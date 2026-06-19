<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id');
            $table->string('order_number', 50)->unique();
            $table->string('booking_reference', 50)->nullable();
            $table->enum('status', ['quote', 'order', 'confirm', 'cancel', 'invoice', 'void', 'refund', 'partial_refund', 'paid', 'partial_paid'])->default('quote');
            $table->char('currency_code', 3);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->string('gds_source', 50)->nullable();
            $table->unsignedBigInteger('gds_parsed_record_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('tenant_id');
            $table->index('company_id');
            $table->index('customer_id');
            $table->index('vendor_id');
            $table->index('created_by_user_id');
            $table->index('updated_by_user_id');
            $table->index('status');
            $table->index('booking_reference');
            $table->index('gds_parsed_record_id');
            $table->unique(['tenant_id', 'uid']);
            $table->unique(['company_id', 'order_number']);

            // Constraints
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('restrict');
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('set null')->nullable();
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('currency_code')->references('code')->on('currencies')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
