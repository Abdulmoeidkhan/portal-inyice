<?php

namespace App\Mcp\OAuth;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\CryptKeyInterface;

class AccessToken extends \Laravel\Passport\Bridge\AccessToken
{
    private CryptKeyInterface $signingKey;

    public function setPrivateKey(CryptKeyInterface $privateKey): void
    {
        $this->signingKey = $privateKey;
    }

    public function toString(): string
    {
        $jwt = Configuration::forAsymmetricSigner(new Sha256,
            InMemory::plainText($this->signingKey->getKeyContents(), $this->signingKey->getPassPhrase() ?? ''),
            InMemory::plainText('unused'));

        return $jwt->builder()
            ->issuedBy(Metadata::issuer())
            ->permittedFor($this->getClient()->getIdentifier(), Metadata::resource())
            ->identifiedBy($this->getIdentifier())
            ->issuedAt(new DateTimeImmutable)->canOnlyBeUsedAfter(new DateTimeImmutable)
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getUserIdentifier() ?? $this->getClient()->getIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->getToken($jwt->signer(), $jwt->signingKey())->toString();
    }
}
