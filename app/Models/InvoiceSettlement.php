<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\TenantAware;

class InvoiceSettlement extends Model
{
    use HasFactory, TenantAware;

    protected $fillable = [
        'tenant_id',
        'uid',
        'invoice_id',
        'amount_received',
        'amount_refunded',
        'amount_to_advance',
        'settlement_date',
        'settlement_type',
        'reference_document_id',
        'reference_document_type',
        'status',
        'notes',
    ];

    protected $casts = [
        'amount_received' => 'decimal:4',
        'amount_refunded' => 'decimal:4',
        'amount_to_advance' => 'decimal:4',
        'settlement_date' => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
