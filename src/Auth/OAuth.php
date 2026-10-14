<?php

declare(strict_types=1);

namespace YouTubeMcp\Auth;

use DateInterval;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\{AuthCodeGrant, RefreshTokenGrant};
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use RuntimeException;
use Throwable;
use YouTubeMcp\{Config, ServiceError};
use YouTubeMcp\Storage\{Database, Lock};

final class OAuth
{
    public readonly AuthorizationServer $server;
    public readonly OAuthRepository $repository;
    private Lock $locks;

    public function __construct(private Database $db, private Config $config, ?Lock $locks = null)
    {
        $this->locks = $locks ?? new Lock($config->storage() . '/locks');

        $this->repository = new OAuthRepository($db, $config->url());
        $this->server = new AuthorizationServer(
            $this->repository,
            $this->repository,
            $this->repository,
            'file://' . $config->storage() . '/oauth-private.pem',
            base64_decode($config->get('APP_KEY'), true),
        );

        $grant = new AuthCodeGrant($this->repository, $this->repository, new DateInterval('PT5M'));
        $grant->setRefreshTokenTTL(new DateInterval('P30D'));
        $this->server->enableGrantType($grant, new DateInterval('PT1H'));

        $refresh = new RefreshTokenGrant($this->repository);
        $refresh->setRefreshTokenTTL(new DateInterval('P30D'));
        $this->server->enableGrantType($refresh, new DateInterval('PT1H'));

        $this->server->setDefaultScope('playlists.read');
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function metadata(): array
    {
        $issuer = $this->config->url();

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'registration_endpoint' => $issuer . '/oauth/register',
            'scopes_supported' => Scope::SUPPORTED,
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function register(array $data, bool $public = false): array
    {
        $redirects = $data['redirect_uris'] ?? [];
        if (!is_array($redirects) || !$redirects || count($redirects) > 5) {
            throw new ServiceError('invalid_client_metadata', 'Supply permitted redirect_uris.');
        }

        foreach ($redirects as $uri) {
            if (!is_string($uri) || !in_array($uri, $this->config->list('OAUTH_REDIRECT_URIS'), true)) {
                error_log(
                    json_encode(
                        [
                            'event' => 'oauth_registration_callback_rejected',
                            'redirect_uri' => is_string($uri) ? substr($uri, 0, 2048) : null,
                        ],
                    ),
                );
                throw new ServiceError('invalid_redirect_uri', 'Callback URL is not in the operator allowlist.');
            }
        }

        $method = $data['token_endpoint_auth_method'] ?? 'none';
        if (!in_array($method, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            throw new ServiceError('invalid_client_metadata', 'Unsupported client authentication.');
        }
        if ($public && ($method !== 'none' || count($redirects) !== 1)) {
            throw new ServiceError(
                'invalid_client_metadata',
                'Public registration requires one callback and token_endpoint_auth_method none. Create confidential clients through the operator CLI.',
            );
        }
        $redirects = array_values(array_unique($redirects));
        sort($redirects);

        return $this->db->transaction(
            function () use ($data, $public, $method, $redirects) {
                if ($method === 'none') {
                    $existing = $this->db->one(
                        'SELECT id,name FROM oauth_clients WHERE confidential=0 AND redirect_uris=? ORDER BY id LIMIT 1',
                        [json_encode($redirects)],
                    );
                    if ($existing) {
                        return [
                            'client_id' => $existing['id'],
                            'client_name' => $existing['name'],
                            'redirect_uris' => $redirects,
                            'token_endpoint_auth_method' => 'none',
                            'grant_types' => ['authorization_code', 'refresh_token'],
                            'response_types' => ['code'],
                        ];
                    }
                }

                $id = bin2hex(random_bytes(24));
                $secret = $method === 'none' ? null : bin2hex(random_bytes(32));
                $name = $public ? 'ChatGPT' : mb_substr((string) ($data['client_name'] ?? 'ChatGPT'), 0, 100);
                $this->db->execute(
                    'INSERT INTO oauth_clients VALUES (?,?,?,?,?)',
                    [
                        $id,
                        $name,
                        json_encode($redirects),
                        $secret ? password_hash($secret, PASSWORD_DEFAULT) : null,
                        (int) ($secret !== null),
                    ],
                );

                return [
                    'client_id' => $id,
                    'client_name' => $name,
                    'redirect_uris' => $redirects,
                    'token_endpoint_auth_method' => $method,
                    'grant_types' => ['authorization_code', 'refresh_token'],
                    'response_types' => ['code'],
                    'client_id_issued_at' => time(),
                ] + ($secret ? ['client_secret' => $secret, 'client_secret_expires_at' => 0] : []);
            },
        );
    }

    public function exchange(ServerRequestInterface $request): ResponseInterface
    {
        // SQLite's write transaction serializes validation/issuance against every revocation writer.
        return $this->locks->run(
            'oauth-token',
            fn() => $this->db->transaction(
                fn() => $this->server->respondToAccessTokenRequest($request, new Response()),
            ),
        );
    }

    /**
     * @param array{
     *     id: string,
     *     scopes: list<string>,
     *     token_id: string,
     *     expires_at: int,
     *     client_id: string,
     *     generation: string|null
     * } $identity
     */
    public function runAuthenticated(array $identity, callable $work): mixed
    {
        return $this->locks->run(
            'account:' . $identity['id'],
            function () use ($identity, $work) {
                $connection = $this->db->one('SELECT generation FROM google_connections WHERE user_id=?', [$identity['id']]);
                if (
                    $identity['expires_at'] <= time()
                    || $this->repository->isAccessTokenRevoked($identity['token_id'])
                    || !$this->db->one('SELECT id FROM users WHERE id=?', [$identity['id']])
                    || ($connection['generation'] ?? null) !== $identity['generation']
                ) {
                    throw new ServiceError('AUTHENTICATION_REQUIRED', 'Session expired or revoked. Reconnect using OAuth.', [], 401);
                }

                return $work();
            },
        );
    }

    public function validateAuthorization(
        ServerRequestInterface $request,
    ): AuthorizationRequestInterface {
        $query = $request->getQueryParams();
        if (($query['resource'] ?? null) !== $this->config->url() . '/mcp') {
            throw new ServiceError('invalid_target', 'The resource must be this service MCP endpoint.');
        }
        if (
            ($query['code_challenge_method'] ?? null) !== 'S256'
            || !preg_match(
                '/^[A-Za-z0-9_-]{43}$/',
                (string) ($query['code_challenge'] ?? ''),
            )
        ) {
            throw new ServiceError('invalid_request', 'PKCE S256 is required.');
        }

        return $this->server->validateAuthorizationRequest($request);
    }

    /**
     * @return array{
     *     id: string,
     *     scopes: list<string>,
     *     token_id: string,
     *     expires_at: int,
     *     client_id: string,
     *     generation: string|null
     * }
     */
    public function authenticate(ServerRequestInterface $request): array
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer (\S+)$/i', $header, $match)) {
            throw new ServiceError('AUTHENTICATION_REQUIRED', 'Connect this service using OAuth.', [], 401);
        }
        try {
            $claims = (array) JWT::decode(
                $match[1],
                new Key(file_get_contents($this->config->storage() . '/oauth-public.pem'), 'RS256'),
            );
            if (
                ($claims['iss'] ?? null) !== $this->config->url()
                || ($claims['aud'] ?? null) !== $this->config->url() . '/mcp'
                || !is_string(
                    $claims['sub'] ?? null,
                )
                || $this->repository->isAccessTokenRevoked((string) ($claims['jti'] ?? ''))
            ) {
                throw new RuntimeException('Invalid token.');
            }
            if (!$this->db->one('SELECT id FROM users WHERE id=?', [$claims['sub']])) {
                throw new RuntimeException('Unknown user.');
            }

            $connection = $this->db->one('SELECT generation FROM google_connections WHERE user_id=?', [$claims['sub']]);

            return [
                'id' => $claims['sub'],
                'scopes' => (array) ($claims['scopes'] ?? []),
                'token_id' => $claims['jti'],
                'expires_at' => $claims['exp'],
                'client_id' => $claims['client_id'],
                'generation' => $connection['generation'] ?? null,
            ];
        } catch (Throwable) {
            throw new ServiceError(
                'AUTHENTICATION_REQUIRED',
                'Session expired or revoked. Reconnect using OAuth.',
                [],
                401,
            );
        }
    }
}
