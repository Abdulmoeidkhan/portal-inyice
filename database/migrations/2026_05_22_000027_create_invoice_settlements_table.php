<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('invoice_id');
            $table->decimal('amount_received', 18, 4)->default(0);
            $table->decimal('amount_refunded', 18, 4)->default(0);
            $table->decimal('amount_to_advance', 18, 4)->default(0);
            $table->date('settlement_date');
            $table->enum('settlement_type', ['payment', 'refund', 'advance'])->default('payment');
            $table->unsignedBigInteger('reference_document_id')->nullable();
            $table->string('reference_document_type', 100)->nullable();
            $table->enum('status', ['pending', 'confirmed'])->default('confirmed');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('invoice_id');
            $table->index('settlement_date');
            $table->unique(['tenant_id', 'uid']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_settlements');
    }
};
