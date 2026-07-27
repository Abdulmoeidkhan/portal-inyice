<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('monthly_invoice_limit')->nullable()->default(null)->change();
            $table->unsignedInteger('order_limit')->nullable()->default(null)->change();
        });

        DB::table('companies')->update([
            'monthly_invoice_limit' => null,
            'order_limit' => null,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('companies')->whereNull('monthly_invoice_limit')->update([
            'monthly_invoice_limit' => 15,
        ]);

        DB::table('companies')->whereNull('order_limit')->update([
            'order_limit' => 20,
        ]);

        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('monthly_invoice_limit')->default(15)->change();
            $table->unsignedInteger('order_limit')->default(20)->change();
        });
    }
};
