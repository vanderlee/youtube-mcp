<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Auth;

use YouTubeMusicMcp\{ServiceError};
use YouTubeMusicMcp\Music\Api;
use YouTubeMusicMcp\Storage\{Database, Lock};
use YouTubeMusicMcp\YouTube\Api as YouTubeApi;

final class MusicBrowser
{
    public function __construct(private Database $db, private Vault $vault, private Google $google,
        private YouTubeApi $youtube, private Api $api, private Lock $locks) {}

    public function connect(string $user, string $cookie, string $index): void
    {
        $credentials = MusicBrowserCredentials::parse($cookie, $index);
        $this->locks->run('account:' . $user, function () use ($user, $credentials) {
            $google = $this->google->connection($user);
            $account = $this->api->withBrowser($credentials)->call('get_music_account', []);
            $handle = $account['channelHandle'] ?? '';
            if (!is_string($handle) || !preg_match('/^@[^\s\x00-\x1f]{1,100}$/uD', $handle)) {
                throw new ServiceError('MUSIC_BROWSER_IDENTITY_UNVERIFIED', 'Music did not provide an active channel handle. No browser session was saved.', [], 403);
            }
            $channels = $this->youtube->call($user, 'channels.list', ['part' => 'id', 'forHandle' => $handle]);
            if (count($channels['items'] ?? []) !== 1 || ($channels['items'][0]['id'] ?? '') !== $google['channel_id']) {
                throw new ServiceError('MUSIC_CHANNEL_MISMATCH', 'Browser Music session must match the confirmed YouTube channel. No session was saved.', [], 403);
            }
            $this->db->transaction(function () use ($user, $google, $credentials, $handle) {
                $this->db->execute('INSERT INTO music_browser_connections (user_id,channel_id,google_generation,generation,credentials,channel_handle,expires_at) VALUES (?,?,?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET channel_id=excluded.channel_id,google_generation=excluded.google_generation,generation=excluded.generation,credentials=excluded.credentials,channel_handle=excluded.channel_handle,expires_at=excluded.expires_at',
                    [$user, $google['channel_id'], $google['generation'], bin2hex(random_bytes(16)), $this->vault->encrypt($credentials), $handle, time() + 7 * 86400]);
                $this->db->execute('UPDATE oauth_tokens SET revoked=1 WHERE user_id=?', [$user]);
            });
        });
    }

    public function connection(string $user, array $google): ?array
    {
        $row = $this->db->one('SELECT * FROM music_browser_connections WHERE user_id=?', [$user]);
        if (!$row) { return null; }
        if ($row['google_generation'] !== $google['generation'] || $row['channel_id'] !== $google['channel_id'] || $row['expires_at'] <= time()) {
            throw new ServiceError('MUSIC_BROWSER_RECONNECT_REQUIRED', 'Reconnect the Music browser session in the dashboard.', [], 401);
        }
        return $row;
    }

    /** Caller holds account lock. Validate active identity before every account operation. */
    public function client(array $row): Api
    {
        $api = $this->api->withBrowser($this->vault->decrypt($row['credentials']));
        $account = $api->call('get_music_account', []);
        if (($account['channelHandle'] ?? '') !== $row['channel_handle']) {
            throw new ServiceError('MUSIC_BROWSER_RECONNECT_REQUIRED', 'Music browser identity changed. Reconnect in the dashboard.', [], 401);
        }
        return $api;
    }

    public function disconnect(string $user): void
    {
        $this->locks->run('account:' . $user, fn() => $this->db->transaction(function () use ($user) {
            $this->db->execute('DELETE FROM music_browser_connections WHERE user_id=?', [$user]);
            $this->db->execute('UPDATE oauth_tokens SET revoked=1 WHERE user_id=?', [$user]);
        }));
    }
}
