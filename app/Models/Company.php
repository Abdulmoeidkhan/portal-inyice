<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use App\Traits\TenantAware;

class Company extends Model
{
    use HasFactory, TenantAware;

    protected $fillable = [
        'tenant_id',
        'uid',
        'legal_name',
        'display_name',
        'email',
        'phone',
        'address',
        'country_code',
        'base_currency_code',
        'default_timezone',
        'monthly_invoice_limit',
        'order_limit',
        'user_limit',
        'is_paid',
        'logo_path',
        'footer_logo_path',
        'is_active',
    ];

    protected $appends = [
        'logo_url',
        'footer_logo_url',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_paid' => 'boolean',
        'monthly_invoice_limit' => 'integer',
        'order_limit' => 'integer',
        'user_limit' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Company $company): void {
            $company->monthly_invoice_limit ??= 15;
            $company->order_limit ??= 20;
            $company->user_limit ??= 2;
            $company->is_paid ??= false;
        });
    }

    /**
     * Get the tenant this company belongs to
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get all users for this company
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function getFooterLogoUrlAttribute(): ?string
    {
        return $this->footer_logo_path ? Storage::disk('public')->url($this->footer_logo_path) : null;
    }
}
