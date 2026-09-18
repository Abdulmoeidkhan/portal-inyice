<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->json('tax_settings')->nullable();
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->json('tax_settings')->nullable();
        });
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->string('tax_category', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', fn (Blueprint $table) => $table->dropColumn('tax_category'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('tax_settings'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('tax_settings'));
    }
};
