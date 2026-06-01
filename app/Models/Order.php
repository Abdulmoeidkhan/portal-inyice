<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\TenantAware;

class Order extends Model
{
    use HasFactory, TenantAware;

    protected $fillable = [
        'tenant_id',
        'uid',
        'company_id',
        'customer_id',
        'vendor_id',
        'created_by_user_id',
        'updated_by_user_id',
        'order_number',
        'booking_reference', // PNR or other booking ref
        'status',
        'currency_code',
        'total_amount',
        'notes',
        'gds_source', // 'sabre', 'galileo', etc.
        'gds_parsed_record_id', // Link to parsed GDS data
    ];

    protected $casts = [
        'total_amount' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

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
     * Get all status history
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderByDesc('created_at');
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
            'quote' => ['order'],
            'order' => ['confirm', 'cancel'],
            'confirm' => ['invoice', 'refund'],
            'cancel' => [],
            'invoice' => ['paid', 'partial_paid'],
            'refund' => [],
            'void' => [],
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
        if (!$this->canTransitionTo($newStatus)) {
            return false;
        }

        $oldStatus = $this->status;
        $this->status = $newStatus;
        $this->save();

        // Record the transition
        OrderStatusHistory::create([
            'tenant_id' => $this->tenant_id,
            'order_id' => $this->id,
            'from_status' => $oldStatus,
            'to_status' => $newStatus,
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

        return true;
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
