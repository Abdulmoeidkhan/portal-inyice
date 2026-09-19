<?php

namespace Tests\Support;

use App\Mcp\OAuth\AccessToken;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\ClientRepository;
use Laravel\Passport\Bridge\Scope;
use League\OAuth2\Server\CryptKey;
use phpseclib4\Crypt\RSA;

trait AgencyOAuthTokens
{
    private static array $keys = [];

    protected function configureOAuthKeys(): void
    {
        if (self::$keys === []) {
            $key = RSA::createKey(2048);
            self::$keys = ['private' => $key->toString('PKCS8'), 'public' => $key->getPublicKey()->toString('PKCS8')];
        }
        config(['passport.private_key' => self::$keys['private'], 'passport.public_key' => self::$keys['public']]);
    }

    protected function token(User $user, array $scopes = ['mcp:use'], ?DateTimeImmutable $expires = null): string
    {
        $client = app(\Laravel\Passport\ClientRepository::class)->createAuthorizationCodeGrantClient(
            'Tool test', ['https://chatgpt.com/connector_platform_oauth_redirect'], confidential: false);
        $entity = new AccessToken((string) $user->id, array_map(fn ($scope) => new Scope($scope), $scopes),
            app(ClientRepository::class)->getClientEntity($client->id));
        $entity->setIdentifier(Str::random(80));
        $entity->setExpiryDateTime($expires ?? new DateTimeImmutable('+1 hour'));
        $entity->setPrivateKey(new CryptKey(self::$keys['private']));
        app(AccessTokenRepository::class)->persistNewAccessToken($entity);

        return $entity->toString();
    }
}
