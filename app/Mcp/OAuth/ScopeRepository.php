<?php

namespace App\Mcp\OAuth;

use App\Mcp\AgencyAccess;
use App\Models\User;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

class ScopeRepository extends \Laravel\Passport\Bridge\ScopeRepository
{
    public function finalizeScopes(array $scopes, string $grantType, ClientEntityInterface $clientEntity, ?string $userIdentifier = null, ?string $authCodeId = null): array
    {
        // Apply account status checks to refreshes as well as initial grants.
        $user = User::find($userIdentifier);
        if (! AgencyAccess::eligible($user)) {
            throw OAuthServerException::invalidGrant('This agency account cannot connect.');
        }

        foreach ($scopes as $scope) {
            if ($scope->getIdentifier() === 'orders:create' && ! AgencyAccess::canCreateOrders($user)) {
                throw OAuthServerException::invalidGrant('This role cannot create orders.');
            }
        }

        return parent::finalizeScopes($scopes, $grantType, $clientEntity, $userIdentifier, $authCodeId);
    }
}
