<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->unique()->constrained()->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->date('start_date');
            $table->date('locked_through')->nullable();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();
        });
        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('book_id')->constrained('accounting_books')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 160);
            $table->string('type', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['book_id', 'code']);
        });
        Schema::create('accounting_journals', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('book_id')->constrained('accounting_books')->restrictOnDelete();
            $table->uuid('request_id');
            $table->string('number', 40)->nullable();
            $table->date('entry_date');
            $table->string('kind', 20);
            $table->string('status', 20)->default('draft');
            $table->string('reference', 160)->nullable();
            $table->text('description');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('posted_by_user_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('accounting_journals')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['book_id', 'request_id']);
            $table->unique(['book_id', 'number']);
            $table->index(['book_id', 'status', 'entry_date']);
        });
        Schema::create('accounting_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('accounting_journals')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounting_accounts')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            // Four decimal places, stored as integers to avoid float rounding.
            $table->bigInteger('debit_units')->default(0);
            $table->bigInteger('credit_units')->default(0);
        });
        Schema::create('accounting_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('accounting_books')->restrictOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('user_name', 200);
            $table->string('action', 60);
            $table->string('subject', 80);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at');
            $table->index(['book_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_audit_events');
        Schema::dropIfExists('accounting_journal_lines');
        Schema::dropIfExists('accounting_journals');
        Schema::dropIfExists('accounting_accounts');
        Schema::dropIfExists('accounting_books');
    }
};
