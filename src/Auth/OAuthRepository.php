<?php
declare(strict_types=1);
namespace YouTubeMcp\Auth;
use League\OAuth2\Server\Entities\{ClientEntityInterface,AccessTokenEntityInterface,AuthCodeEntityInterface,RefreshTokenEntityInterface,ScopeEntityInterface};
use League\OAuth2\Server\Repositories\{ClientRepositoryInterface,AccessTokenRepositoryInterface,AuthCodeRepositoryInterface,RefreshTokenRepositoryInterface,ScopeRepositoryInterface};
use YouTubeMcp\Storage\Database;

final class OAuthRepository implements ClientRepositoryInterface, AccessTokenRepositoryInterface, AuthCodeRepositoryInterface, RefreshTokenRepositoryInterface, ScopeRepositoryInterface
{
    public function __construct(private Database $db, private string $issuer) {}
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $r = $this->db->one('SELECT * FROM oauth_clients WHERE id=?',[$clientIdentifier]);
        return $r ? new Client($r['id'],$r['name'],json_decode($r['redirect_uris'],true), (bool)$r['confidential']) : null;
    }
    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $r = $this->db->one('SELECT * FROM oauth_clients WHERE id=?',[$clientIdentifier]);
        return $r && (!$r['confidential'] || ($clientSecret !== null && password_verify($clientSecret,$r['secret_hash'])));
    }
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface { return in_array($identifier,['playlists.read','playlists.write'],true) ? new Scope($identifier) : null; }
    public function finalizeScopes(array $scopes, string $grantType, ClientEntityInterface $clientEntity, ?string $userIdentifier = null, ?string $authCodeId = null): array { return $scopes; }
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    { $token = new AccessToken($this->issuer); $token->setClient($clientEntity); $token->setUserIdentifier($userIdentifier); foreach ($scopes as $scope) { $token->addScope($scope); } return $token; }
    private function persist(string $kind, string $id, ?string $user, string $client, int $expiry): void
    { $this->db->execute('INSERT INTO oauth_tokens(id,kind,user_id,client_id,expires_at) VALUES (?,?,?,?,?)',[hash('sha256',$id),$kind,$user,$client,$expiry]); }
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void { $this->persist('access',$accessTokenEntity->getIdentifier(),$accessTokenEntity->getUserIdentifier(),$accessTokenEntity->getClient()->getIdentifier(),$accessTokenEntity->getExpiryDateTime()->getTimestamp()); }
    public function getNewAuthCode(): AuthCodeEntityInterface { return new AuthCode(); }
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void { $this->persist('code',$authCodeEntity->getIdentifier(),$authCodeEntity->getUserIdentifier(),$authCodeEntity->getClient()->getIdentifier(),$authCodeEntity->getExpiryDateTime()->getTimestamp()); }
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface { return new RefreshToken(); }
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void { $a = $refreshTokenEntity->getAccessToken(); $this->persist('refresh',$refreshTokenEntity->getIdentifier(),$a->getUserIdentifier(),$a->getClient()->getIdentifier(),$refreshTokenEntity->getExpiryDateTime()->getTimestamp()); }
    private function revoke(string $id): void { $this->db->execute('UPDATE oauth_tokens SET revoked=1 WHERE id=?',[hash('sha256',$id)]); }
    private function revoked(string $id, string $kind): bool { $r = $this->db->one('SELECT * FROM oauth_tokens WHERE id=? AND kind=?',[hash('sha256',$id),$kind]); return !$r || $r['revoked'] || $r['expires_at'] <= time(); }
    public function revokeAccessToken(string $tokenId): void { $this->revoke($tokenId); }
    public function isAccessTokenRevoked(string $tokenId): bool { return $this->revoked($tokenId,'access'); }
    public function revokeAuthCode(string $codeId): void { $this->revoke($codeId); }
    public function isAuthCodeRevoked(string $codeId): bool { return $this->revoked($codeId,'code'); }
    public function revokeRefreshToken(string $tokenId): void { $this->revoke($tokenId); }
    public function isRefreshTokenRevoked(string $tokenId): bool { return $this->revoked($tokenId,'refresh'); }
}
