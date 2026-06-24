<?php

namespace App\Models;

use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderVendorCost extends Model
{
    use TenantAware;

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
}
