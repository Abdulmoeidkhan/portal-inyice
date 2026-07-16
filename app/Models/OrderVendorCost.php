<?php

namespace App\Models;

use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderVendorCost extends Model
{
    use TenantAware;

    public const SERVICE_SECTIONS = [
        'hotels' => 'hotel',
        'transfers' => 'transfer',
        'city_tours' => 'city_tour',
        'visa' => 'visa',
        'other_services' => 'other_service',
    ];

    protected $fillable = [
        'tenant_id',
        'order_id',
        'vendor_id',
        'service_type',
        'service_index',
        'amount',
    ];

    protected $casts = [
        'service_index' => 'integer',
        'amount' => 'decimal:4',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public static function amountFromServiceRow(array $row): float
    {
        return self::toAmount(self::firstFilledValue($row['cost'] ?? null, $row['amount'] ?? null));
    }

    public static function toAmount(mixed $value): float
    {
        if ($value === null) {
            return 0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = preg_replace('/[^0-9.\-]/', '', $value) ?: '0';
            return is_numeric($normalized) ? (float) $normalized : 0;
        }

        return 0;
    }

    public static function firstFilledValue(mixed ...$values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
