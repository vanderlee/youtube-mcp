<?php

declare(strict_types=1);

namespace YouTubeMcp\Auth;

use YouTubeMcp\Data;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use YouTubeMcp\Storage\Database;

/**
 * Connects the League OAuth server's entity repositories to SQLite.
 *
 * Persists grants and token revocation state so OAuth can enforce client and user authorization.
 */
final class OAuthRepository implements
    ClientRepositoryInterface,
    AccessTokenRepositoryInterface,
    AuthCodeRepositoryInterface,
    RefreshTokenRepositoryInterface,
    ScopeRepositoryInterface
{
    public function __construct(private Database $db, private string $issuer) {}

    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $row = $this->db->one('SELECT * FROM oauth_clients WHERE id=?', [$clientIdentifier]);

        return $row ? new Client(
            Data::string($row['id']),
            Data::string($row['name']),
            Data::strings(json_decode(Data::string($row['redirect_uris']), true, 512, JSON_THROW_ON_ERROR)),
            (bool) $row['confidential'],
        ) : null;
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $row = $this->db->one('SELECT * FROM oauth_clients WHERE id=?', [$clientIdentifier]);

        if (!$row) {
            return false;
        }
        if (!$row['confidential']) {
            return true;
        }

        return $clientSecret !== null && password_verify($clientSecret, Data::string($row['secret_hash']));
    }

    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        return in_array($identifier, Scope::SUPPORTED, true) ? new Scope($identifier) : null;
    }

    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null,
    ): array {
        return $scopes;
    }

    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        ?string $userIdentifier = null,
    ): AccessTokenEntityInterface {
        if ($userIdentifier === null || $userIdentifier === '') {
            throw new \InvalidArgumentException('A user identifier is required for access tokens.');
        }

        $token = new AccessToken($this->issuer);
        $token->setClient($clientEntity);
        $token->setUserIdentifier($userIdentifier);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }

        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $this->persist(
            'access',
            $accessTokenEntity->getIdentifier(),
            $accessTokenEntity->getUserIdentifier(),
            $accessTokenEntity->getClient()->getIdentifier(),
            $accessTokenEntity->getExpiryDateTime()->getTimestamp(),
        );
    }

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCode();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $this->persist(
            'code',
            $authCodeEntity->getIdentifier(),
            $authCodeEntity->getUserIdentifier(),
            $authCodeEntity->getClient()->getIdentifier(),
            $authCodeEntity->getExpiryDateTime()->getTimestamp(),
        );
    }

    public function getNewRefreshToken(): RefreshTokenEntityInterface
    {
        return new RefreshToken();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $accessToken = $refreshTokenEntity->getAccessToken();
        $this->persist(
            'refresh',
            $refreshTokenEntity->getIdentifier(),
            $accessToken->getUserIdentifier(),
            $accessToken->getClient()->getIdentifier(),
            $refreshTokenEntity->getExpiryDateTime()->getTimestamp(),
        );
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $this->revoke($tokenId);
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        return $this->revoked($tokenId, 'access');
    }

    public function revokeAuthCode(string $codeId): void
    {
        $this->revoke($codeId);
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        return $this->revoked($codeId, 'code');
    }

    public function revokeRefreshToken(string $tokenId): void
    {
        $this->revoke($tokenId);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        return $this->revoked($tokenId, 'refresh');
    }

    private function persist(string $kind, string $id, ?string $user, string $client, int $expiry): void
    {
        $this->db->execute(
            'INSERT INTO oauth_tokens(id,kind,user_id,client_id,expires_at) VALUES (?,?,?,?,?)',
            [hash('sha256', $id), $kind, $user, $client, $expiry],
        );
    }

    private function revoke(string $id): void
    {
        $this->db->execute('UPDATE oauth_tokens SET revoked=1 WHERE id=?', [hash('sha256', $id)]);
    }

    private function revoked(string $id, string $kind): bool
    {
        $row = $this->db->one('SELECT * FROM oauth_tokens WHERE id=? AND kind=?', [hash('sha256', $id), $kind]);

        return !$row || $row['revoked'] || $row['expires_at'] <= time();
    }
}
