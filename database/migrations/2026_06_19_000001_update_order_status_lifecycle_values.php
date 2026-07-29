<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY status ENUM('quote','order','confirm','cancel','invoice','void','refund_request','refund','partial_refund','paid','partial_paid') NOT NULL DEFAULT 'quote'");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::table('orders')
                ->where('status', 'refund_request')
                ->update(['status' => 'refund']);

            DB::table('orders')
                ->where('status', 'partial_refund')
                ->update(['status' => 'refund']);

            DB::statement("ALTER TABLE orders MODIFY status ENUM('quote','order','confirm','cancel','invoice','refund','void','paid','partial_paid') NOT NULL DEFAULT 'quote'");
        }
    }
};
