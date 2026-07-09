<?php

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
                $meta = json_decode((string) ($order->meta ?? ''), true);
                if (!is_array($meta)) {
                    continue;
                }

                foreach (($meta['pricing'] ?? []) as $index => $pricing) {
                    $vendorId = (int) ($pricing['vendor_id'] ?? $order->vendor_id ?? 0);
                    $amount = (float) ($pricing['flight_cost'] ?? 0);
                    if ($vendorId <= 0 || $amount <= 0 || (int) ($vendorTenants[$vendorId] ?? 0) !== (int) $order->tenant_id) {
                        continue;
                    }

                    $rows[] = $this->row($order, $vendorId, 'flight', (int) $index, $amount, $now);
                }

                foreach (($meta['visa'] ?? []) as $index => $visa) {
                    $vendorId = (int) ($visa['vendor_id'] ?? 0);
                    $amount = (float) ($this->firstFilledValue($visa['cost'] ?? null, $visa['amount'] ?? null) ?? 0);
                    if ($vendorId <= 0 || $amount <= 0 || (int) ($vendorTenants[$vendorId] ?? 0) !== (int) $order->tenant_id) {
                        continue;
                    }

                    $rows[] = $this->row($order, $vendorId, 'visa', (int) $index, $amount, $now);
                }

                foreach ([
                    'hotels' => 'hotel',
                    'transfers' => 'transfer',
                    'city_tours' => 'city_tour',
                    'other_services' => 'other_service',
                ] as $section => $serviceType) {
                    foreach (($meta[$section] ?? []) as $index => $serviceRow) {
                        $vendorId = (int) ($serviceRow['vendor_id'] ?? 0);
                        $amount = (float) ($this->firstFilledValue($serviceRow['cost'] ?? null, $serviceRow['amount'] ?? null) ?? 0);
                        if ($vendorId <= 0 || $amount <= 0 || (int) ($vendorTenants[$vendorId] ?? 0) !== (int) $order->tenant_id) {
                            continue;
                        }

                        $rows[] = $this->row($order, $vendorId, $serviceType, (int) $index, $amount, $now);
                    }
                }
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

    private function row(object $order, int $vendorId, string $serviceType, int $serviceIndex, float $amount, mixed $timestamp): array
    {
        return [
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'vendor_id' => $vendorId,
            'service_type' => $serviceType,
            'service_index' => $serviceIndex,
            'amount' => $amount,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    private function firstFilledValue(mixed ...$values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return $value;
            }
        }

        return null;
    }
};
