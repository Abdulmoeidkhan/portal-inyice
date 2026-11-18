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
            || ! in_array('mcp:use', $token->oauth_scopes, true)
            || array_diff($token->oauth_scopes, ['mcp:use', 'orders:create']) || $token->revoked
            || ! $token->expires_at?->isFuture()) {
            throw new AuthorizationException('MCP access requires a paid agency plan, an active owner, admin or accounts user, and an authorized OAuth connection.');
        }

        return $user;
    }

    public static function canCreateOrders(?User $user): bool
    {
        return self::eligible($user) && $user->hasAnyRole(['admin']);
    }

    public static function authorizeOrderCreation(?User $user): User
    {
        $user = self::authorize($user);
        if (! self::canCreateOrders($user) || ! $user->tokenCan('orders:create')) {
            throw new AuthorizationException('Order creation requires an owner/admin and a connection authorized for orders:create.');
        }

        return $user;
    }
}
