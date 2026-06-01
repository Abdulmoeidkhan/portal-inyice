<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\TenantAware;

class OrderStatusHistory extends Model
{
    use HasFactory, TenantAware;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'uid',
        'order_id',
        'from_status',
        'to_status',
        'user_id',
        'notes',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Get the tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the order
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the user who made the transition
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to recent transitions first
     */
    public function scopeRecent($query)
    {
        return $query->orderByDesc('created_at');
    }
}
