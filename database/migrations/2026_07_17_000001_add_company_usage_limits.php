<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('monthly_invoice_limit')->default(15)->after('default_timezone');
            $table->unsignedInteger('user_limit')->default(2)->after('monthly_invoice_limit');
            $table->string('logo_path')->nullable()->after('user_limit');
            $table->string('footer_logo_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['monthly_invoice_limit', 'user_limit', 'logo_path', 'footer_logo_path']);
        });
    }
};
