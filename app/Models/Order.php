<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Traits\TenantAware;

class Order extends Model
{
    use HasFactory, TenantAware, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'uid',
        'share_token',
        'company_id',
        'customer_id',
        'vendor_id',
        'created_by_user_id',
        'updated_by_user_id',
        'order_number',
        'voucher_no',
        'issue_date',
        'package_type',
        'active_sections',
        'emergency_contact',
        'booking_reference', // PNR or other booking ref
        'status',
        'currency_code',
        'total_amount',
        'notes',
        'gds_source', // 'sabre', 'galileo', etc.
        'gds_parsed_record_id', // Link to parsed GDS data
        'meta',
    ];

    protected $casts = [
        'total_amount' => 'decimal:4',
        'issue_date' => 'date:Y-m-d',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getActiveSectionsAttribute(mixed $value): array
    {
        return self::decodeMeta($value);
    }

    public function setActiveSectionsAttribute(mixed $value): void
    {
        $this->attributes['active_sections'] = is_array($value)
            ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : $value;
    }

    public function getMetaAttribute(mixed $value): array
    {
        return self::decodeMeta($value);
    }

    public function setMetaAttribute(mixed $value): void
    {
        $this->attributes['meta'] = is_array($value)
            ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : $value;
    }

    /**
     * Get the tenant this order belongs to
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the company this order belongs to
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the customer for this order
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the vendor for this order
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Per-service supplier costs. The vendor_id column above is retained for legacy orders.
     */
    public function vendorCosts(): HasMany
    {
        return $this->hasMany(OrderVendorCost::class);
    }

    /**
     * Get the user who created this order
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the user who last updated this order
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Get the GDS parsed record
     */
    public function gdsParsedRecord(): BelongsTo
    {
        return $this->belongsTo(GdsParsedRecord::class);
    }

    /**
     * Get all order items
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the invoice generated from this order.
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->orderByDesc('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderByDesc('id');
    }

    /**
     * Get all status history
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderByDesc('created_at');
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(OrderInternalNote::class)->orderBy('created_at')->orderBy('id');
    }

    public function vendorPaymentAllocations(): HasMany
    {
        return $this->hasMany(VendorPaymentAllocation::class);
    }

    /**
     * Get current status
     */
    public function currentStatus(): string
    {
        return $this->status;
    }

    /**
     * Check if order can transition to a specific status
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $transitions = [
            'quote' => ['order', 'cancel', 'invoice'],
            'order' => ['cancel', 'invoice'],
            'cancel' => [],
            'invoice' => ['void', 'refund_request', 'refund', 'partial_refund', 'paid', 'partial_paid'],
            'void' => [],
            'refund_request' => ['partial_refund', 'refund', 'cancel'],
            'refund' => [],
            'partial_refund' => ['refund'],
            'paid' => [],
            'partial_paid' => ['paid'],
        ];

        return in_array($newStatus, $transitions[$this->status] ?? []);
    }

    /**
     * Transition order to a new status
     */
    public function transitionTo(string $newStatus): bool
    {
        return DB::transaction(function () use ($newStatus): bool {
            $order = self::whereKey($this->getKey())->lockForUpdate()->first();

            if (!$order || !$order->canTransitionTo($newStatus)) {
                return false;
            }

            $oldStatus = $order->status;
            $order->status = $newStatus;
            $order->save();

            OrderStatusHistory::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'from_status' => $oldStatus,
                'to_status' => $newStatus,
                'user_id' => auth()->id(),
                'created_at' => now(),
            ]);

            $this->setRawAttributes($order->getAttributes(), true);

            return true;
        });
    }

    public function ensureShareToken(): string
    {
        if (!$this->share_token) {
            $this->forceFill(['share_token' => Str::random(64)])->save();
        }

        return $this->share_token;
    }

    public function voucherSearchAttributes(): array
    {
        $meta = $this->meta ?? [];

        return self::voucherSearchAttributesFromMeta(is_array($meta) ? $meta : []);
    }

    public static function voucherSearchAttributesFromMeta(array $meta): array
    {
        return [
            'voucher_no' => $meta['voucher_no'] ?? null,
            'issue_date' => ($meta['issue_date'] ?? null) ?: null,
            'package_type' => $meta['package_type'] ?? null,
            'active_sections' => $meta['active_sections'] ?? null,
            'emergency_contact' => $meta['emergency_contact'] ?? null,
        ];
    }

    public static function voucherSearchDatabaseAttributesFromMeta(array $meta): array
    {
        $attributes = self::voucherSearchAttributesFromMeta($meta);

        if (is_array($attributes['active_sections'])) {
            $attributes['active_sections'] = json_encode($attributes['active_sections'], JSON_THROW_ON_ERROR);
        }

        return $attributes;
    }

    public static function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }

        if (!is_string($meta) || trim($meta) === '') {
            return [];
        }

        $candidate = $meta;

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $decoded = json_decode($candidate, true);

            if (is_array($decoded)) {
                return $decoded;
            }

            if (is_string($decoded)) {
                $candidate = $decoded;
                continue;
            }

            $unescaped = stripslashes($candidate);
            if ($unescaped === $candidate) {
                break;
            }

            $candidate = $unescaped;
        }

        return [];
    }

    public function syncVendorCostsFromVoucher(array $voucher): void
    {
        $rows = self::vendorCostRowsFromVoucher(
            $voucher,
            $this->id,
            $this->tenant_id,
            $this->vendor_id
        );

        DB::transaction(function () use ($rows): void {
            $this->vendorCosts()->delete();

            if ($rows !== []) {
                OrderVendorCost::insert($rows);
            }
        });
    }

    public static function vendorCostRowsFromVoucher(
        array $voucher,
        int $orderId,
        int $tenantId,
        ?int $fallbackVendorId = null,
        mixed $timestamp = null,
        ?array $allowedVendorTenantIds = null
    ): array {
        $rows = [];
        $timestamp ??= now();

        foreach (($voucher['pricing'] ?? []) as $index => $pricing) {
            $vendorId = (int) ($pricing['vendor_id'] ?? $fallbackVendorId ?? 0);
            $amount = OrderVendorCost::toAmount($pricing['flight_cost'] ?? null);

            if (!self::canUseVendorCost($vendorId, $amount, $tenantId, $allowedVendorTenantIds)) {
                continue;
            }

            $rows[] = self::vendorCostRow($orderId, $tenantId, $vendorId, 'flight', (int) $index, $amount, $timestamp);
        }

        foreach (OrderVendorCost::SERVICE_SECTIONS as $section => $serviceType) {
            foreach (($voucher[$section] ?? []) as $index => $serviceRow) {
                $vendorId = (int) ($serviceRow['vendor_id'] ?? 0);
                $amount = OrderVendorCost::amountFromServiceRow((array) $serviceRow);

                if (!self::canUseVendorCost($vendorId, $amount, $tenantId, $allowedVendorTenantIds)) {
                    continue;
                }

                $rows[] = self::vendorCostRow($orderId, $tenantId, $vendorId, $serviceType, (int) $index, $amount, $timestamp);
            }
        }

        return $rows;
    }

    public function vendorPayableAmountFor(int $vendorId): float
    {
        if ($this->relationLoaded('vendorCosts') ? $this->vendorCosts->isNotEmpty() : $this->vendorCosts()->exists()) {
            return (float) $this->vendorCosts()
                ->where('vendor_id', $vendorId)
                ->sum('amount');
        }

        $amount = collect(self::vendorCostRowsFromVoucher($this->meta ?? [], $this->id, $this->tenant_id, $this->vendor_id))
            ->where('vendor_id', $vendorId)
            ->sum('amount');

        if ($amount != 0.0) {
            return (float) $amount;
        }

        return (int) $this->vendor_id === $vendorId ? (float) $this->total_amount : 0;
    }

    private static function canUseVendorCost(int $vendorId, float $amount, int $tenantId, ?array $allowedVendorTenantIds): bool
    {
        if ($vendorId <= 0 || $amount == 0.0) {
            return false;
        }

        return $allowedVendorTenantIds === null || (int) ($allowedVendorTenantIds[$vendorId] ?? 0) === $tenantId;
    }

    private static function vendorCostRow(
        int $orderId,
        int $tenantId,
        int $vendorId,
        string $serviceType,
        int $serviceIndex,
        float $amount,
        mixed $timestamp
    ): array {
        return [
            'tenant_id' => $tenantId,
            'order_id' => $orderId,
            'vendor_id' => $vendorId,
            'service_type' => $serviceType,
            'service_index' => $serviceIndex,
            'amount' => $amount,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /**
     * Scope by status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope by customer
     */
    public function scopeByCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }
}
