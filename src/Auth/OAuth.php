<?php

declare(strict_types=1);

namespace YouTubeMcp\Auth;

use DateInterval;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\{AuthCodeGrant, RefreshTokenGrant};
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\{Config, ServiceError};
use RuntimeException;
use Throwable;
use YouTubeMcp\Storage\Database;

final class OAuth
{
    public readonly AuthorizationServer $server;
    public readonly OAuthRepository $repository;

    public function __construct(private Database $db, private Config $config)
    {
        $this->repository = new OAuthRepository($db, $config->url());
        $this->server = new AuthorizationServer(
            $this->repository,
            $this->repository,
            $this->repository,
            'file://' . $config->storage() . '/oauth-private.pem',
            base64_decode($config->get('APP_KEY'), true)
        );
        $grant = new AuthCodeGrant($this->repository, $this->repository, new DateInterval('PT5M'));
        $grant->setRefreshTokenTTL(new DateInterval('P30D'));
        $this->server->enableGrantType($grant, new DateInterval('PT1H'));
        $refresh = new RefreshTokenGrant($this->repository);
        $refresh->setRefreshTokenTTL(new DateInterval('P30D'));
        $this->server->enableGrantType($refresh, new DateInterval('PT1H'));
        $this->server->setDefaultScope('playlists.read');
    }

    public function metadata(): array
    {
        $u = $this->config->url();
        return [
            'issuer' => $u,
            'authorization_endpoint' => $u . '/oauth/authorize',
            'token_endpoint' => $u . '/oauth/token',
            'registration_endpoint' => $u . '/oauth/register',
            'scopes_supported' => ['playlists.read', 'playlists.write'],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic']
        ];
    }

    public function register(array $data): array
    {
        if (($this->db->one('SELECT COUNT(*) AS n FROM oauth_clients')['n'] ?? 0) >= 200) {
            throw new ServiceError(
                'CLIENT_REGISTRATION_LIMIT',
                'Client registration capacity reached. Contact the operator.',
                [],
                429
            );
        }
        $redirects = $data['redirect_uris'] ?? [];
        if (!is_array($redirects) || !$redirects || count($redirects) > 5) {
            throw new ServiceError('invalid_client_metadata', 'Supply permitted redirect_uris.');
        }
        foreach ($redirects as $uri) {
            if (!is_string($uri) || !in_array($uri, $this->config->list('OAUTH_REDIRECT_URIS'), true)) {
                error_log(json_encode([
                    'event' => 'oauth_registration_callback_rejected',
                    'redirect_uri' => is_string($uri) ? substr($uri, 0, 2048) : null,
                ]));
                throw new ServiceError('invalid_redirect_uri', 'Callback URL is not in the operator allowlist.');
            }
        }
        $method = $data['token_endpoint_auth_method'] ?? 'none';
        if (!in_array($method, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            throw new ServiceError('invalid_client_metadata', 'Unsupported client authentication.');
        }
        $id = bin2hex(random_bytes(24));
        $secret = $method === 'none' ? null : bin2hex(random_bytes(32));
        $name = mb_substr((string)($data['client_name'] ?? 'ChatGPT'), 0, 100);
        $this->db->execute(
            'INSERT INTO oauth_clients VALUES (?,?,?,?,?)',
            [
                $id,
                $name,
                json_encode($redirects),
                $secret ? password_hash($secret, PASSWORD_DEFAULT) : null,
                (int)($secret !== null)
            ]
        );
        return [
                'client_id' => $id,
                'client_name' => $name,
                'redirect_uris' => $redirects,
                'token_endpoint_auth_method' => $method,
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'client_id_issued_at' => time()
            ] + ($secret ? ['client_secret' => $secret, 'client_secret_expires_at' => 0] : []);
    }

    public function validateAuthorization(ServerRequestInterface $request
    ): AuthorizationRequestInterface {
        $q = $request->getQueryParams();
        if (($q['resource'] ?? null) !== $this->config->url() . '/mcp') {
            throw new ServiceError('invalid_target', 'The resource must be this service MCP endpoint.');
        }
        if (($q['code_challenge_method'] ?? null) !== 'S256' || !preg_match(
                '/^[A-Za-z0-9_-]{43}$/',
                (string)($q['code_challenge'] ?? '')
            )) {
            throw new ServiceError('invalid_request', 'PKCE S256 is required.');
        }
        return $this->server->validateAuthorizationRequest($request);
    }

    public function authenticate(ServerRequestInterface $request): array
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer (\S+)$/i', $header, $match)) {
            throw new ServiceError('AUTHENTICATION_REQUIRED', 'Connect this service using OAuth.', [], 401);
        }
        try {
            $claims = (array)JWT::decode(
                $match[1],
                new Key(file_get_contents($this->config->storage() . '/oauth-public.pem'), 'RS256')
            );
            if (($claims['iss'] ?? null) !== $this->config->url() || ($claims['aud'] ?? null) !== $this->config->url() . '/mcp' || !is_string(
                    $claims['sub'] ?? null
                ) || $this->repository->isAccessTokenRevoked((string)($claims['jti'] ?? ''))) {
                throw new RuntimeException('Invalid token.');
            }
            if (!$this->db->one('SELECT id FROM users WHERE id=?', [$claims['sub']])) {
                throw new RuntimeException('Unknown user.');
            }
            return ['id' => $claims['sub'], 'scopes' => (array)($claims['scopes'] ?? [])];
        } catch (Throwable) {
            throw new ServiceError(
                'AUTHENTICATION_REQUIRED',
                'Session expired or revoked. Reconnect using OAuth.',
                [],
                401
            );
        }
    }
}
