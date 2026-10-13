<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use YouTubeMusicMcp\Auth\{Google, Vault};
use YouTubeMusicMcp\Http\Client;
use YouTubeMusicMcp\Quota\Budget;
use YouTubeMusicMcp\ServiceError;
use YouTubeMusicMcp\Storage\Lock;

trait GoogleTestHelpers
{
    private string $dir;


}

uses(GoogleTestHelpers::class);

test('youtube connection requests both official and music scopes without broadening sign in', function (): void {
    $cfg = $this->config(['GOOGLE_CLIENT_ID' => 'dummy-client']);
    $google = new Google($cfg, $this->database(), new Vault($cfg->get('APP_KEY')), new Client(), new Lock($this->dir));
    parse_str(parse_url($google->authorize('state', 'challenge', true), PHP_URL_QUERY), $connect);
    $scopes = explode(' ', $connect['scope']);
    $this->assertContains(Google::YOUTUBE_SCOPE, $scopes);
    $this->assertContains(Google::MUSIC_SCOPE, $scopes);
    $this->assertSame('offline', $connect['access_type']);
    $this->assertSame('consent', $connect['prompt']);
    parse_str(parse_url($google->authorize('state', 'challenge', false), PHP_URL_QUERY), $signin);
    $this->assertSame('openid email profile', $signin['scope']);
});

test('google accepts the broader music grant when google collapses overlapping scopes', function (): void {
    $db = $this->database();
    $this->user($db);
    $cfg = $this->config();
    $google = new Google($cfg, $db, new Vault($cfg->get('APP_KEY')), new Client(), new Lock($this->dir));
    $google->connect('u1', ['scope' => Google::MUSIC_SCOPE, 'refresh_token' => 'dummy-refresh', 'access_token' => 'dummy-access'],
        ['id' => 'dummy-channel', 'snippet' => ['title' => 'Dummy']]);
    $this->assertSame(Google::MUSIC_SCOPE, $google->connection('u1')['scopes']);
});

test('deleting and recreating user preserves usage history', function (): void {
    $db = $this->database();
    $cfg = $this->config(['ALLOW_REGISTRATION' => 'true']);
    $g = new Google($cfg, $db, new Vault($cfg->get('APP_KEY')), new Client(), new Lock($this->dir));
    $identity = ['subject' => 'https://accounts.google.com|example', 'email' => 'person@example.test'];
    $first = $g->signIn($identity);
    $budget = new Budget($db, $cfg);
    $budget->dispatch($first, 'videos.list');
    $db->execute('DELETE FROM users WHERE id=?', [$first]);
    $second = $g->signIn($identity);
    $this->assertSame($first, $second);
    $this->assertSame(1, $budget->status($second)['buckets']['general']['user_spent']);
});

test('refresh updates only the connected users encrypted tokens', function (): void {
    $db = $this->database();
    $this->user($db);
    $this->user($db, 'u2');
    $cfg = $this->config();
    $v = new Vault($cfg->get('APP_KEY'));
    foreach (['u1', 'u2'] as $u) {
        $db->execute(
            'INSERT INTO google_connections VALUES (?,?,?,?,?,?)',
            [
                $u,
                'channel-' . $u,
                'name',
                $v->encrypt(
                    ['access_token' => 'expired-' . $u, 'refresh_token' => 'refresh-' . $u, 'expires_at' => 0]
                ),
                Google::YOUTUBE_SCOPE,
                'g'
            ]
        );
    }
    $http = new class extends Client {
        public function request(string $method, string $url, array $headers = [], ?string $body = null): array
        {
            parse_str($body, $p);
            return [
                'status' => 200,
                'data' => ['access_token' => 'new-' . $p['refresh_token'], 'expires_in' => 3600]
            ];
        }
    };
    $g = new Google($cfg, $db, $v, $http, new Lock($this->dir));
    $this->assertSame('new-refresh-u1', $g->accessToken('u1'));
    $this->assertSame(
        'expired-u2',
        $v->decrypt(
            $db->one('SELECT tokens FROM google_connections WHERE user_id=?', ['u2'])['tokens']
        )['access_token']
    );
    $this->assertSame(
        'refresh-u1',
        $v->decrypt(
            $db->one('SELECT tokens FROM google_connections WHERE user_id=?', ['u1'])['tokens']
        )['refresh_token']
    );
});

test('invite only onboarding rejects unknown email', function (): void {
    $db = $this->database();
    $cfg = $this->config(['ALLOWED_EMAILS' => 'invited@example.test']);
    $g = new Google($cfg, $db, new Vault($cfg->get('APP_KEY')), new Client(), new Lock($this->dir));
    $this->expectException(ServiceError::class);
    $g->signIn(['subject' => 'issuer|other', 'email' => 'other@example.test']);
});

beforeEach(function (): void {
    $this->dir = $this->tempDir();
});

afterEach(function (): void {
    $this->removeDir($this->dir);
});
