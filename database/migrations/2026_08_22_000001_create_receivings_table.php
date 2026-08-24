<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivings', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->string('receiving_number', 50);
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_id');
            $table->decimal('amount', 18, 4);
            $table->string('status', 20)->default('received');
            $table->string('paid_by', 190);
            $table->unsignedBigInteger('received_by_user_id');
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedBigInteger('reference_customer_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('received_at');
            $table->unsignedBigInteger('deleted_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('company_id');
            $table->index('received_by_user_id');
            $table->index('created_by_user_id');
            $table->index('updated_by_user_id');
            $table->index('reference_customer_id');
            $table->index('status');
            $table->index('received_at');
            $table->index('deleted_at');
            $table->unique(['tenant_id', 'uid']);
            $table->unique(['company_id', 'receiving_number']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('received_by_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('reference_customer_id')->references('id')->on('customers')->onDelete('set null');
            $table->foreign('deleted_by_user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivings');
    }
};
