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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->string('legal_name', 200);
            $table->string('display_name', 200);
            $table->string('email', 200)->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->char('base_currency_code', 3);
            $table->string('default_timezone', 80)->default('UTC');
            $table->unsignedInteger('monthly_invoice_limit')->nullable();
            $table->unsignedInteger('order_limit')->nullable();
            $table->unsignedInteger('user_limit')->default(2);
            $table->boolean('is_paid')->default(false);
            $table->boolean('sales_can_edit_cost')->default(false);
            $table->string('logo_path')->nullable();
            $table->string('footer_logo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Indexes
            $table->index('tenant_id');
            $table->index(['tenant_id', 'base_currency_code']);
            $table->unique(['tenant_id', 'uid']);

            // Constraints
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('base_currency_code')->references('code')->on('currencies')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
