<?php

namespace App\Mcp;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Passport\AccessToken;

class AgencyAccess
{
    public static function eligible(?User $user): bool
    {
        return $user && $user->is_active && $user->hasVerifiedEmail()
            && ! $user->isSystemUser() && $user->hasAnyRole(['admin', 'accounts'])
            && $user->tenant?->is_active && $user->company?->is_active
            && $user->company->hasIntegrationAccess()
            && (int) $user->company->tenant_id === (int) $user->tenant_id;
    }

    public static function authorize(?User $user): User
    {
        $token = $user?->currentAccessToken();

        if (! self::eligible($user) || ! $token instanceof AccessToken
            || $token->oauth_scopes !== ['mcp:use'] || $token->revoked
            || ! $token->expires_at?->isFuture()) {
            throw new AuthorizationException('MCP access requires a paid agency plan, an active owner, admin or accounts user, and an authorized OAuth connection.');
        }

        return $user;
    }
}
