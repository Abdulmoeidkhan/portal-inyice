<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->char('from_currency_code', 3);
            $table->char('to_currency_code', 3);
            $table->decimal('rate', 18, 8);
            $table->date('rate_date');
            $table->enum('source', ['api', 'manual', 'admin_override'])->default('manual');
            $table->string('api_source_name', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['from_currency_code', 'to_currency_code']);
            $table->index('rate_date');
            $table->unique(['tenant_id', 'from_currency_code', 'to_currency_code', 'rate_date'], 'exchange_rates_tenant_pair_date_unique');

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('from_currency_code')->references('code')->on('currencies')->onDelete('restrict');
            $table->foreign('to_currency_code')->references('code')->on('currencies')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
