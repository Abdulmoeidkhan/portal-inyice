<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\TenantAware;

class Vendor extends Model
{
    use HasFactory, TenantAware;

    protected $fillable = [
        'tenant_id',
        'uid',
        'company_id',
        'type', // 'B2B' or 'B2C'
        'name',
        'email',
        'phone',
        'address',
        'city',
        'country_code',
        'postal_code',
        'tax_id',
        'currency_code',
        'b2b_agency_id', // For B2B: reference to other agency's tenant
        'payment_terms',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the tenant this vendor belongs to
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the company this vendor belongs to
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the B2B linked tenant (for B2B vendors)
     */
    public function b2bAgency(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'b2b_agency_id');
    }

    /**
     * Get all orders involving this vendor
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get payments made to this vendor.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    /**
     * Scope to B2B vendors only
     */
    public function scopeB2b($query)
    {
        return $query->where('type', 'B2B');
    }

    /**
     * Scope to B2C vendors only
     */
    public function scopeB2c($query)
    {
        return $query->where('type', 'B2C');
    }

    /**
     * Scope to active vendors
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
