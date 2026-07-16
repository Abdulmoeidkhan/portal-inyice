<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_vendor_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('service_type', 30);
            $table->unsignedInteger('service_index');
            $table->decimal('amount', 15, 4)->default(0);
            $table->timestamps();

            $table->unique(['order_id', 'service_type', 'service_index'], 'order_vendor_cost_service_unique');
            $table->index(['tenant_id', 'vendor_id'], 'order_vendor_costs_tenant_vendor_index');
        });

        $vendorTenants = DB::table('vendors')->pluck('tenant_id', 'id');
        $now = now();

        DB::table('orders')->orderBy('id')->chunkById(200, function ($orders) use ($vendorTenants, $now): void {
            $rows = [];

            foreach ($orders as $order) {
                $meta = Order::decodeMeta($order->meta ?? null);
                if ($meta === []) {
                    continue;
                }

                $rows = array_merge($rows, Order::vendorCostRowsFromVoucher(
                    voucher: $meta,
                    orderId: (int) $order->id,
                    tenantId: (int) $order->tenant_id,
                    fallbackVendorId: $order->vendor_id ? (int) $order->vendor_id : null,
                    timestamp: $now,
                    allowedVendorTenantIds: $vendorTenants->all(),
                ));
            }

            if ($rows !== []) {
                DB::table('order_vendor_costs')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_vendor_costs');
    }

};
