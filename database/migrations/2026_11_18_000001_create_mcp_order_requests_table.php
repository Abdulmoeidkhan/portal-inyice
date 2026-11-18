<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_order_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('request_id');
            $table->char('payload_hash', 64);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'request_id']);
            $table->index(['tenant_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_order_requests');
    }
};
