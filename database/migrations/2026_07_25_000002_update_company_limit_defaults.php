<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('monthly_invoice_limit')->default(15)->change();
            $table->unsignedInteger('user_limit')->default(2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('monthly_invoice_limit')->default(50)->change();
            $table->unsignedInteger('user_limit')->default(4)->change();
        });
    }
};
