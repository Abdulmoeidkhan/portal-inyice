<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\TenantAware;

class Receipt extends Model
{
    use HasFactory, TenantAware;

    protected $fillable = [
        'tenant_id',
        'uid',
        'company_id',
        'customer_id',
        'vendor_id',
        'receipt_number',
        'receipt_date',
        'amount',
        'currency_code',
        'payment_method',
        'account_id',
        'account_type',
        'reference_number',
        'description',
        'created_by_user_id',
    ];

    protected $casts = [
        'receipt_date' => 'date',
        'amount' => 'decimal:4',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(InvoiceSettlement::class, 'reference_document_id')
            ->where('reference_document_type', self::class);
    }

    public function refundAllocations(): HasMany
    {
        return $this->hasMany(RefundAllocation::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uid';
    }
}
