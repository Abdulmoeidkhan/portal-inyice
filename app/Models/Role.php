<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'uid',
        'tenant_id',
        'code',
        'name',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    /**
     * Get the tenant this role belongs to (null means provider-level)
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withDefault();
    }

    /**
     * Get all users with this role
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Check if this is a system role (provider-level)
     */
    public function isSystemRole(): bool
    {
        return $this->is_system;
    }

    /**
     * Check if this is a tenant role
     */
    public function isTenantRole(): bool
    {
        return !$this->is_system && $this->tenant_id !== null;
    }
}
