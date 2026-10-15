<?php

declare(strict_types=1);

namespace YouTubeMcp\Auth;

use YouTubeMcp\{Config, ServiceError};
use YouTubeMcp\Http\Client;
use YouTubeMcp\Storage\{Database, Lock};

final class Google
{
    public const YOUTUBE_SCOPE = 'https://www.googleapis.com/auth/youtube.force-ssl';

    public function __construct(
        private Config $config,
        private Database $db,
        private Vault $vault,
        private Client $http,
        private Lock $lock,
    ) {}

    public function authorize(string $state, string $challenge, bool $youtube): string
    {
        if (!$this->config->get('GOOGLE_CLIENT_ID')) {
            throw new ServiceError('SETUP_REQUIRED', 'Configure Google OAuth credentials first.', [], 503);
        }

        $args = [
            'client_id' => $this->config->get('GOOGLE_CLIENT_ID'),
            'redirect_uri' => $this->config->url() . '/google/callback',
            'response_type' => 'code',
            'scope' => 'openid email profile' . ($youtube ? ' ' . self::YOUTUBE_SCOPE : ''),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
        if ($youtube) {
            $args += ['access_type' => 'offline', 'prompt' => 'consent'];
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($args);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function exchange(string $code, string $verifier): array
    {
        return $this->token(
            [
                'code' => $code,
                'code_verifier' => $verifier,
                'redirect_uri' => $this->config->url() . '/google/callback',
                'grant_type' => 'authorization_code',
            ],
        );
    }

    /**
     * @return array{subject: string, email: string}
     */
    public function identity(string $accessToken): array
    {
        $response = $this->http->request(
            'GET',
            'https://openidconnect.googleapis.com/v1/userinfo',
            ['Authorization: Bearer ' . $accessToken],
        );

        $profile = $response['data'];

        if (
            $response['status'] !== 200
            || empty($profile['sub'])
            || ($profile['email_verified'] ?? false) !== true
        ) {
            throw new ServiceError('INVALID_IDENTITY', 'Google did not provide a verified identity.', [], 401);
        }

        return [
            'subject' => 'https://accounts.google.com|' . $profile['sub'],
            'email' => strtolower($profile['email']),
        ];
    }

    /**
     * @param array{subject: string, email: string} $identity
     */
    public function signIn(array $identity): string
    {
        $existing = $this->db->one('SELECT id FROM users WHERE subject=?', [$identity['subject']]);
        if ($existing) {
            return $existing['id'];
        }

        if (
            !$this->config->bool('ALLOW_REGISTRATION')
            && !in_array(
                $identity['email'],
                array_map('strtolower', $this->config->list('ALLOWED_EMAILS')),
                true,
            )
        ) {
            throw new ServiceError(
                'REGISTRATION_DISABLED',
                'Your account is not invited to this installation.',
                [],
                403,
            );
        }

        // Keep usage history tied to the same installation-local identity after account recreation.
        $id = substr(
            hash_hmac('sha256', $identity['subject'], $this->config->appKey()),
            0,
            32,
        );

        $this->db->execute(
            'INSERT INTO users VALUES (?,?,?,?) ON CONFLICT(subject) DO NOTHING',
            [$id, $identity['subject'], $identity['email'], time()],
        );

        $user = $this->db->one('SELECT id FROM users WHERE subject=?', [$identity['subject']]);
        if ($user === null) {
            throw new \RuntimeException('Cannot load signed-in user.');
        }

        return $user['id'];
    }

    /**
     * @param array<string, mixed> $channel
     * @param array<array-key, mixed> $tokens
     */
    public function connect(string $user, array $tokens, array $channel): void
    {
        $scopes = preg_split('/\s+/', (string) ($tokens['scope'] ?? '')) ?: [];
        if (
            (
                !in_array(self::YOUTUBE_SCOPE, $scopes, true)
                && !in_array('https://www.googleapis.com/auth/youtube', $scopes, true)
            )
            || empty($tokens['refresh_token'])
        ) {
            throw new ServiceError(
                'GOOGLE_RECONNECT_REQUIRED',
                'Offline YouTube permission is required. Reconnect and grant access.',
            );
        }

        $this->lock->run(
            'account:' . $user,
            fn() => $this->db->transaction(
                function () use ($user, $tokens, $channel, $scopes) {
                    $this->db->execute('UPDATE oauth_tokens SET revoked=1 WHERE user_id=?', [$user]);
                    $this->db->execute(
                        'INSERT INTO google_connections VALUES (?,?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET channel_id=excluded.channel_id,channel_name=excluded.channel_name,tokens=excluded.tokens,scopes=excluded.scopes,generation=excluded.generation',
                        [
                            $user,
                            $channel['id'],
                            $channel['snippet']['title'],
                            $this->vault->encrypt($tokens),
                            implode(' ', $scopes),
                            bin2hex(random_bytes(16)),
                        ],
                    );
                    $this->db->execute('DELETE FROM api_cache WHERE user_id=?', [$user]);
                },
            ),
        );
    }

    public function accessToken(string $user): string
    {
        return $this->lock->run(
            'google:' . $user,
            function () use ($user) {
                $connection = $this->connection($user);
                $tokens = $this->vault->decrypt($connection['tokens']);
                if (($tokens['expires_at'] ?? 0) <= time() + 60) {
                    // Google authorization failures do not revoke the independent MCP authorization.
                    $refreshedTokens = $this->token(
                        ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']],
                    );

                    $tokens = array_replace($tokens, $refreshedTokens);
                    $this->db->execute(
                        'UPDATE google_connections SET tokens=? WHERE user_id=?',
                        [$this->vault->encrypt($tokens), $user],
                    );
                }

                return $tokens['access_token'];
            },
        );
    }

    /**
     * @return array{
     *     user_id: string,
     *     channel_id: string,
     *     channel_name: string,
     *     tokens: string,
     *     scopes: string,
     *     generation: string
     * }
     */
    public function connection(string $user): array
    {
        $connection = $this->db->one('SELECT * FROM google_connections WHERE user_id=?', [$user]) ?? throw new ServiceError(
            'GOOGLE_RECONNECT_REQUIRED',
            'Connect your Google account in the service dashboard.',
            [],
            401,
        );

        return [
            'user_id' => $connection['user_id'],
            'channel_id' => $connection['channel_id'],
            'channel_name' => $connection['channel_name'],
            'tokens' => $connection['tokens'],
            'scopes' => $connection['scopes'],
            'generation' => $connection['generation'],
        ];
    }

    /**
     * @return array{local_disconnected: bool, google_revoked: bool}
     */
    public function disconnect(string $user): array
    {
        return $this->lock->run(
            'account:' . $user,
            fn() => $this->lock->run(
                'google:' . $user,
                function () use ($user) {
                    $connection = $this->db->one('SELECT tokens FROM google_connections WHERE user_id=?', [$user]);
                    $revoked = false;
                    if ($connection) {
                        $tokens = $this->vault->decrypt($connection['tokens']);
                        try {
                            $response = $this->http->request(
                                'POST',
                                'https://oauth2.googleapis.com/revoke',
                                ['Content-Type: application/x-www-form-urlencoded'],
                                http_build_query(['token' => $tokens['refresh_token']]),
                            );
                            $revoked = $response['status'] === 200;
                        } catch (ServiceError) {
                            // Local disconnection still proceeds; the result reports unconfirmed Google revocation.
                        }
                    }

                    $this->db->transaction(
                        function () use ($user) {
                            $this->db->execute('DELETE FROM google_connections WHERE user_id=?', [$user]);
                            $this->db->execute('UPDATE oauth_tokens SET revoked=1 WHERE user_id=?', [$user]);
                            $this->db->execute('DELETE FROM api_cache WHERE user_id=?', [$user]);
                        },
                    );

                    return ['local_disconnected' => true, 'google_revoked' => $revoked];
                },
            ),
        );
    }

    /**
     * @param array<string, string> $args
     * @return array<array-key, mixed>
     */
    private function token(array $args): array
    {
        $response = $this->http->request(
            'POST',
            'https://oauth2.googleapis.com/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(
                $args + [
                    'client_id' => $this->config->get('GOOGLE_CLIENT_ID'),
                    'client_secret' => $this->config->get('GOOGLE_CLIENT_SECRET'),
                ],
            ),
        );
        if (
            $response['status'] >= 500
            || $response['status'] === 429
        ) {
            throw new ServiceError(
                'GOOGLE_UNAVAILABLE',
                'Google authorization is temporarily unavailable. Retry later.',
                [],
                503,
            );
        }
        if ($response['status'] !== 200) {
            if (($response['data']['error'] ?? null) !== 'invalid_grant') {
                throw new ServiceError(
                    'GOOGLE_UNAVAILABLE',
                    'Google authorization failed. Check the service OAuth configuration or retry later.',
                    [],
                    503,
                );
            }

            throw new ServiceError(
                'GOOGLE_RECONNECT_REQUIRED',
                'Google authorization failed. Reconnect your account.',
                [],
                401,
            );
        }

        return $response['data'] + ['expires_at' => time() + (int) ($response['data']['expires_in'] ?? 3600)];
    }
}
