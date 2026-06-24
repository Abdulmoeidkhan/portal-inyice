<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('voucher_no', 100)->nullable()->after('order_number');
            $table->date('issue_date')->nullable()->after('voucher_no');
            $table->string('package_type', 100)->nullable()->after('issue_date');
            $table->json('active_sections')->nullable()->after('package_type');
            $table->text('emergency_contact')->nullable()->after('active_sections');

            $table->index(['tenant_id', 'voucher_no'], 'orders_tenant_voucher_no_index');
            $table->index(['tenant_id', 'booking_reference'], 'orders_tenant_booking_reference_index');
            $table->index(['tenant_id', 'issue_date'], 'orders_tenant_issue_date_index');
            $table->index(['tenant_id', 'package_type'], 'orders_tenant_package_type_index');
            $table->index(['tenant_id', 'gds_source'], 'orders_tenant_gds_source_index');
        });

        DB::table('orders')->orderBy('id')->chunkById(200, function ($orders): void {
            foreach ($orders as $order) {
                $meta = json_decode((string) ($order->meta ?? ''), true);
                if (!is_array($meta)) {
                    continue;
                }

                DB::table('orders')->where('id', $order->id)->update([
                    'voucher_no' => $meta['voucher_no'] ?? null,
                    'issue_date' => ($meta['issue_date'] ?? null) ?: null,
                    'package_type' => $meta['package_type'] ?? null,
                    'active_sections' => isset($meta['active_sections'])
                        ? json_encode($meta['active_sections'], JSON_THROW_ON_ERROR)
                        : null,
                    'emergency_contact' => $meta['emergency_contact'] ?? null,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_tenant_voucher_no_index');
            $table->dropIndex('orders_tenant_booking_reference_index');
            $table->dropIndex('orders_tenant_issue_date_index');
            $table->dropIndex('orders_tenant_package_type_index');
            $table->dropIndex('orders_tenant_gds_source_index');
            $table->dropColumn([
                'voucher_no',
                'issue_date',
                'package_type',
                'active_sections',
                'emergency_contact',
            ]);
        });
    }
};
