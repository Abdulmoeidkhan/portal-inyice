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
        if (! AgencyAccess::eligible(User::find($userIdentifier))) {
            throw OAuthServerException::invalidGrant('This agency account cannot connect.');
        }

        return parent::finalizeScopes($scopes, $grantType, $clientEntity, $userIdentifier, $authCodeId);
    }
}
