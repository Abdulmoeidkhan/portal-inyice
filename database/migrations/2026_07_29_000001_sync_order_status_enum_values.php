<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CURRENT_STATUSES = [
        'quote',
        'order',
        'confirm',
        'cancel',
        'invoice',
        'void',
        'refund_request',
        'refund',
        'partial_refund',
        'paid',
        'partial_paid',
    ];

    private const PREVIOUS_STATUSES = [
        'quote',
        'order',
        'confirm',
        'cancel',
        'invoice',
        'void',
        'refund',
        'partial_refund',
        'paid',
        'partial_paid',
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE orders MODIFY status ENUM(' . $this->enumValues(self::CURRENT_STATUSES) . ") NOT NULL DEFAULT 'quote'");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::table('orders')
            ->where('status', 'refund_request')
            ->update(['status' => 'refund']);

        DB::statement('ALTER TABLE orders MODIFY status ENUM(' . $this->enumValues(self::PREVIOUS_STATUSES) . ") NOT NULL DEFAULT 'quote'");
    }

    private function enumValues(array $statuses): string
    {
        return collect($statuses)
            ->map(fn (string $status): string => DB::getPdo()->quote($status))
            ->implode(',');
    }
};
