<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\TenantAware;

class Invoice extends Model
{
    use HasFactory, TenantAware;

    protected $fillable = [
        'tenant_id',
        'uid',
        'company_id',
        'order_id',
        'customer_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'currency_code',
        'subtotal',
        'tax_amount',
        'total_amount',
        'outstanding_amount',
        'advance_balance',
        'status', // 'draft', 'issued', 'sent', 'partial_paid', 'paid', 'overdue', 'void'
        'fx_rate_to_base', // Exchange rate used (base currency)
        'notes',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'outstanding_amount' => 'decimal:4',
        'advance_balance' => 'decimal:4',
        'fx_rate_to_base' => 'decimal:8',
    ];

    /**
     * Get the tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the company
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the order
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the customer
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get all invoice lines
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * Get all settlements for this invoice
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(InvoiceSettlement::class);
    }

    /**
     * Calculate outstanding balance
     */
    public function calculateOutstanding(): void
    {
        $totalPaid = $this->settlements()
            ->where('status', 'confirmed')
            ->sum('amount_received');

        $this->outstanding_amount = max(0, $this->total_amount - $totalPaid);
    }

    /**
     * Apply advance balance to invoice
     */
    public function applyAdvance(float $amount): void
    {
        if ($amount > $this->advance_balance) {
            throw new \Exception('Amount exceeds available advance balance');
        }

        $this->outstanding_amount -= $amount;
        $this->advance_balance -= $amount;
        $this->save();
    }

    /**
     * Scope by status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
