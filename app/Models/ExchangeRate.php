<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\TenantAware;

class ExchangeRate extends Model
{
    use HasFactory, TenantAware;

    protected $fillable = [
        'tenant_id',
        'uid',
        'from_currency_code',
        'to_currency_code',
        'rate',
        'rate_date',
        'source', // 'api', 'manual', 'admin_override'
        'api_source_name', // 'openexchangerates', 'fixer', etc.
        'is_active',
    ];

    protected $casts = [
        'rate' => 'decimal:8',
        'rate_date' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Get the tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the latest rate for two currencies
     */
    public static function getRate(int $tenantId, string $fromCurrency, string $toCurrency, ?\DateTime $asOfDate = null): ?float
    {
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        $date = $asOfDate ?? now();

        $rate = self::where('tenant_id', $tenantId)
            ->where('from_currency_code', $fromCurrency)
            ->where('to_currency_code', $toCurrency)
            ->where('rate_date', '<=', $date)
            ->where('is_active', true)
            ->orderByDesc('rate_date')
            ->first();

        return $rate?->rate ? (float)$rate->rate : null;
    }

    /**
     * Scope to active rates
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
