<?php

declare(strict_types=1);

namespace YouTubeMcp\Auth;

use Firebase\JWT\JWT;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * Represents a service access token for the League OAuth server.
 *
 * Signs an RS256 JWT with the MCP audience and client claims consumed by OAuth.
 */
final class AccessToken implements AccessTokenEntityInterface
{
    use EntityTrait;
    use TokenEntityTrait;

    private CryptKeyInterface $privateKey;

    public function __construct(private string $issuer) {}

    public function setPrivateKey(CryptKeyInterface $privateKey): void
    {
        $this->privateKey = $privateKey;
    }

    public function toString(): string
    {
        return JWT::encode(
            [
                'iss' => $this->issuer,
                'aud' => $this->issuer . '/mcp',
                'sub' => $this->getUserIdentifier(),
                'jti' => $this->getIdentifier(),
                'iat' => time(),
                'nbf' => time(),
                'exp' => $this->getExpiryDateTime()->getTimestamp(),
                'client_id' => $this->getClient()->getIdentifier(),
                'scopes' => array_map(fn($scope) => $scope->getIdentifier(), $this->getScopes()),
            ],
            $this->privateKey->getKeyContents(),
            'RS256',
        );
    }
}
