<?php

namespace App\Models;

use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Receiving extends Model
{
    use HasFactory, SoftDeletes, TenantAware;

    protected $fillable = [
        'tenant_id',
        'uid',
        'receiving_number',
        'company_id',
        'amount',
        'status',
        'paid_by',
        'received_by_user_id',
        'created_by_user_id',
        'updated_by_user_id',
        'reference_customer_id',
        'notes',
        'received_at',
        'deleted_by_user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'received_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function referenceCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'reference_customer_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uid';
    }
}
