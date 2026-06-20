<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable()->after('payment_method');
            $table->enum('account_type', ['cash', 'bank'])->nullable()->after('account_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->unique()->after('uid');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('share_token'));
        Schema::table('receipts', fn (Blueprint $table) => $table->dropColumn(['account_id', 'account_type']));
    }
};
