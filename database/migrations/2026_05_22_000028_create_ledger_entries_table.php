<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('account_id');
            $table->enum('account_type', ['cash', 'bank']);
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->date('entry_date');
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('created_at');

            $table->index('tenant_id');
            $table->index('account_id');
            $table->index('entry_date');
            $table->index('created_at');
            $table->index(['tenant_id', 'account_type', 'account_id', 'entry_date', 'id'], 'ledger_entries_tenant_account_date_id_index');
            $table->unique(['tenant_id', 'uid']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
