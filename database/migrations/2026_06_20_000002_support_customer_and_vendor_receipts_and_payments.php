<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->unsignedBigInteger('vendor_id')->nullable()->after('customer_id');
            $table->foreign('vendor_id')->references('id')->on('vendors')->restrictOnDelete();
            $table->index('vendor_id');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('vendor_id')->nullable()->change();
            $table->unsignedBigInteger('customer_id')->nullable()->after('vendor_id');
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['customer_id']); $table->dropIndex(['customer_id']); $table->dropColumn('customer_id');
            $table->unsignedBigInteger('vendor_id')->nullable(false)->change();
        });
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropForeign(['vendor_id']); $table->dropIndex(['vendor_id']); $table->dropColumn('vendor_id');
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
        });
    }
};
