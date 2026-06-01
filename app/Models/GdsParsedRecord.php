<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\TenantAware;

class GdsParsedRecord extends Model
{
    use HasFactory, TenantAware;

    protected $fillable = [
        'tenant_id',
        'uid',
        'raw_text',
        'gds_source', // 'sabre', 'galileo'
        'booking_reference',
        'parsed_json',
        'parsed_by_user_id',
    ];

    protected $casts = [
        'parsed_json' => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the user who parsed this record
     */
    public function parsedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parsed_by_user_id');
    }

    /**
     * Get all orders using this parsed record
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Convert parsed data to extraction format for UI
     */
    public function getExtractedData(): array
    {
        $parsed = $this->parsed_json ?? [];

        return [
            'booking_reference' => $parsed['booking_reference'] ?? $this->booking_reference,
            'gds_source' => $this->gds_source,
            'passengers' => $parsed['passengers'] ?? [],
            'segments' => $parsed['segments'] ?? [],
            'ticket_info' => $parsed['ticket_info'] ?? [],
        ];
    }
}
