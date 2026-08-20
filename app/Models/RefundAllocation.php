<?php

namespace App\Models;

use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundAllocation extends Model
{
    use TenantAware;

    public const CUSTOMER_PAYMENT = 'customer_payment';
    public const VENDOR_RECEIPT = 'vendor_receipt';

    protected $fillable = [
        'uid',
        'tenant_id',
        'order_id',
        'payment_id',
        'receipt_id',
        'allocation_type',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }
}
