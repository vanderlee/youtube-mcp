<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Auth;

use YouTubeMusicMcp\{Config, ServiceError};
use YouTubeMusicMcp\Http\Client;
use YouTubeMusicMcp\Storage\{Database, Lock};
use YouTubeMusicMcp\YouTube\Api;

/** Separate per-user Music device authorization; official Web OAuth remains unchanged. */
final class MusicOAuth
{
    public function __construct(private Config $config, private Database $db, private Google $google,
        private Vault $vault, private Client $http, private Api $youtube, private Lock $locks)
    {
    }

    public function configured(): bool
    {
        return $this->config->get('MUSIC_OAUTH_CLIENT_ID') !== '' && $this->config->get('MUSIC_OAUTH_CLIENT_SECRET') !== '';
    }

    public function begin(string $user): array
    {
        $this->requireConfiguration();
        return $this->locks->run('account:' . $user, function () use ($user) {
            $connection = $this->google->connection($user);
            $r = $this->http->request('POST', 'https://oauth2.googleapis.com/device/code',
                ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
                    'client_id' => $this->config->get('MUSIC_OAUTH_CLIENT_ID'), 'scope' => Google::MUSIC_SCOPE,
                ]));
            $data = $r['data'];
            $url = $data['verification_url'] ?? $data['verification_uri'] ?? '';
            $parts = is_string($url) ? parse_url($url) : false;
            if ($r['status'] !== 200 || !is_string($data['device_code'] ?? null) || !is_string($data['user_code'] ?? null)
                || !$parts || ($parts['scheme'] ?? '') !== 'https'
                || !in_array($parts['host'] ?? '', ['www.google.com', 'google.com', 'accounts.google.com'], true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
                throw new ServiceError('MUSIC_OAUTH_SETUP_REQUIRED', 'Google rejected Music device authorization. Configure a TVs and Limited Input devices OAuth client.', [], 502);
            }
            $interval = max(5, min(60, (int)($data['interval'] ?? 5)));
            return ['user' => $user, 'google_generation' => $connection['generation'],
                'client_id_hash' => $this->clientHash(), 'device' => $this->vault->encrypt(['device_code' => $data['device_code']]),
                'user_code' => $data['user_code'], 'verification_url' => $url,
                'expires_at' => time() + max(1, min(1800, (int)($data['expires_in'] ?? 0))),
                'interval' => $interval, 'next_poll_at' => time() + $interval];
        });
    }

    /** One user-triggered poll per request; no worker, blocking polling loop or automatic writes. */
    public function poll(string $user, array &$flow): bool
    {
        $this->requireConfiguration();
        return $this->locks->run('account:' . $user, function () use ($user, &$flow) {
            $google = $this->google->connection($user);
            if (($flow['user'] ?? '') !== $user || ($flow['google_generation'] ?? '') !== $google['generation']
                || ($flow['client_id_hash'] ?? '') !== $this->clientHash() || ($flow['expires_at'] ?? 0) <= time()) {
                throw new ServiceError('MUSIC_AUTHORIZATION_EXPIRED', 'Restart Music authorization from the dashboard.', [], 409);
            }
            if (($flow['next_poll_at'] ?? 0) > time()) { return false; }
            $flow['next_poll_at'] = time() + $flow['interval'];
            $device = $this->vault->decrypt($flow['device']);
            $r = $this->token(['device_code' => $device['device_code'], 'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code']);
            $data = $r['data'];
            if ($r['status'] !== 200) {
                if (($data['error'] ?? '') === 'authorization_pending') { return false; }
                if (($data['error'] ?? '') === 'slow_down') {
                    $flow['interval'] = min(300, $flow['interval'] + 5);
                    $flow['next_poll_at'] = time() + $flow['interval'];
                    return false;
                }
                throw new ServiceError('MUSIC_AUTHORIZATION_REJECTED', 'Music authorization was denied, expired or rejected. Restart it and check the device OAuth client.', [], 401);
            }
            $this->validateTokens($data);
            // A token for another account/channel must never replace the caller's Music credentials.
            $channels = $this->youtube->call($user, 'channels.list', ['part' => 'snippet', 'mine' => 'true'], token: $data['access_token']);
            if (count($channels['items'] ?? []) !== 1 || ($channels['items'][0]['id'] ?? '') !== $google['channel_id']) {
                throw new ServiceError('MUSIC_CHANNEL_MISMATCH', 'Authorize the same YouTube channel already confirmed in this dashboard. No Music connection was saved.', [], 403);
            }
            $data['expires_at'] = time() + (int)$data['expires_in'];
            $this->db->transaction(function () use ($user, $google, $data) {
                $this->db->execute('INSERT INTO music_connections VALUES (?,?,?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET channel_id=excluded.channel_id,google_generation=excluded.google_generation,generation=excluded.generation,client_id_hash=excluded.client_id_hash,tokens=excluded.tokens,created_at=excluded.created_at',
                    [$user, $google['channel_id'], $google['generation'], bin2hex(random_bytes(16)), $this->clientHash(), $this->vault->encrypt($data), time()]);
                $this->db->execute('UPDATE oauth_tokens SET revoked=1 WHERE user_id=?', [$user]);
            });
            return true;
        });
    }

    public function connection(string $user, array $google): array
    {
        $row = $this->db->one('SELECT * FROM music_connections WHERE user_id=?', [$user]);
        if (!$row || $row['google_generation'] !== $google['generation'] || $row['channel_id'] !== $google['channel_id']
            || $row['client_id_hash'] !== $this->clientHash()) {
            throw new ServiceError('MUSIC_CONNECT_REQUIRED', 'Connect YouTube Music separately in the dashboard, using the confirmed channel.', [], 401);
        }
        return $row;
    }

    public function status(string $user, array $google): array
    {
        if (!$this->configured()) { return ['music_authentication' => 'google_web_oauth', 'music_device_connected' => false]; }
        try { $this->connection($user, $google); $connected = true; }
        catch (ServiceError $error) {
            if ($error->reason !== 'MUSIC_CONNECT_REQUIRED') { throw $error; }
            $connected = false;
        }
        return ['music_authentication' => 'device_oauth', 'music_device_connected' => $connected];
    }

    /** Called while holding the account lock, matching Music service reads and mutations. */
    public function accessToken(string $user, array $connection): string
    {
        return $this->locks->run('music-token:' . $user, function () use ($user, $connection) {
            $tokens = $this->vault->decrypt($connection['tokens']);
            if (($tokens['expires_at'] ?? 0) <= time() + 60) {
                $r = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
                if ($r['status'] !== 200 || !is_string($r['data']['access_token'] ?? null) || !isset($r['data']['expires_in'])
                    || (int)$r['data']['expires_in'] <= 0 || (isset($r['data']['scope']) && !in_array(Google::MUSIC_SCOPE, preg_split('/\s+/', (string)$r['data']['scope']), true))) {
                    throw new ServiceError('MUSIC_CONNECT_REQUIRED', 'Music authorization could not be refreshed. Reconnect YouTube Music in the dashboard.', [], 401);
                }
                $tokens = array_replace($tokens, $r['data'], ['expires_at' => time() + (int)$r['data']['expires_in']]);
                $this->db->execute('UPDATE music_connections SET tokens=? WHERE user_id=? AND generation=?',
                    [$this->vault->encrypt($tokens), $user, $connection['generation']]);
            }
            return $tokens['access_token'];
        });
    }

    public function disconnect(string $user): void
    {
        $this->locks->run('account:' . $user, fn() => $this->db->transaction(function () use ($user) {
            $this->db->execute('DELETE FROM music_connections WHERE user_id=?', [$user]);
            $this->db->execute('UPDATE oauth_tokens SET revoked=1 WHERE user_id=?', [$user]);
        }));
    }

    private function token(array $args): array
    {
        return $this->http->request('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query($args + ['client_id' => $this->config->get('MUSIC_OAUTH_CLIENT_ID'), 'client_secret' => $this->config->get('MUSIC_OAUTH_CLIENT_SECRET')]));
    }

    private function validateTokens(array $tokens): void
    {
        if (!is_string($tokens['access_token'] ?? null) || !is_string($tokens['refresh_token'] ?? null)
            || !isset($tokens['expires_in']) || (int)$tokens['expires_in'] <= 0
            || !in_array(Google::MUSIC_SCOPE, preg_split('/\s+/', (string)($tokens['scope'] ?? '')), true)) {
            throw new ServiceError('MUSIC_AUTHORIZATION_REJECTED', 'Google did not supply the required offline Music grant.', [], 401);
        }
    }

    private function clientHash(): string { return hash('sha256', $this->config->get('MUSIC_OAUTH_CLIENT_ID')); }
    private function requireConfiguration(): void
    {
        if (!$this->configured()) { throw new ServiceError('MUSIC_OAUTH_SETUP_REQUIRED', 'Configure MUSIC_OAUTH_CLIENT_ID and MUSIC_OAUTH_CLIENT_SECRET for a TVs and Limited Input devices client.', [], 503); }
    }
}
