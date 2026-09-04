<?php

namespace App\Models;

use App\Notifications\WelcomeVerifyEmail;
use App\Traits\TenantAware;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Throwable;

#[Fillable(['tenant_id', 'company_id', 'role_id', 'uid', 'name', 'email', 'password', 'auth_provider', 'auth_provider_id', 'email_verified_at', 'is_active'])]
#[Hidden(['password'])]
class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, TenantAware;

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if ($user->hasVerifiedEmail() || !$user->email) {
                return;
            }

            try {
                $user->sendEmailVerificationNotification();
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the tenant this user belongs to
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the company this user belongs to
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the role this user is assigned
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Check if user has a specific role code
     */
    public function hasRole(string $code): bool
    {
        return $this->role?->code === $code;
    }

    /**
     * Check if user has any role from a provided list.
     *
     * @param array<int, string> $codes
     */
    public function hasAnyRole(array $codes): bool
    {
        $roleCode = $this->role?->code;

        if (!$roleCode) {
            return false;
        }

        if ($roleCode === 'owner' && in_array('admin', $codes, true)) {
            return true;
        }

        return in_array($roleCode, $codes, true);
    }

    /**
     * Check if user is an admin or tenant owner
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin') || $this->hasRole('owner');
    }

    /**
     * Check if user is a provider super-admin
     */
    public function isSuperAdmin(): bool
    {
        return $this->role?->is_system === true && $this->hasRole('super-admin');
    }

    public function isSystemUser(): bool
    {
        return $this->role?->is_system === true;
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new WelcomeVerifyEmail);
    }
}
