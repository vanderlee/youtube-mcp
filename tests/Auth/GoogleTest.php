<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Auth;

use YouTubeMcp\Auth\{Google, Vault};
use YouTubeMcp\Http\Client;
use YouTubeMcp\Quota\Usage;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Lock;

covers(\YouTubeMcp\Auth\Google::class);

trait GoogleTestHelpers
{
    private string $dir;


}

uses(GoogleTestHelpers::class);

test('youtube connection requests only official data api scope without broadening sign in', function (): void {
    $cfg = $this->config(['GOOGLE_CLIENT_ID' => 'dummy-client']);
    $google = new Google($cfg, $this->database(), new Vault($cfg->get('APP_KEY')), new Client(), new Lock($this->dir));
    parse_str(parse_url($google->authorize('state', 'challenge', true), PHP_URL_QUERY), $connect);
    $scopes = explode(' ', $connect['scope']);
    $this->assertContains(Google::YOUTUBE_SCOPE, $scopes);
    $this->assertSame(['openid', 'email', 'profile', Google::YOUTUBE_SCOPE], $scopes);
    $this->assertSame('offline', $connect['access_type']);
    $this->assertSame('consent', $connect['prompt']);
    parse_str(parse_url($google->authorize('state', 'challenge', false), PHP_URL_QUERY), $signin);
    $this->assertSame('openid email profile', $signin['scope']);
});

test('google preserves compatibility with existing broader youtube grants', function (): void {
    $db = $this->database();
    $this->user($db);
    $cfg = $this->config();
    $google = new Google($cfg, $db, new Vault($cfg->get('APP_KEY')), new Client(), new Lock($this->dir));
    $google->connect(
        'u1',
        [
            'scope' => 'https://www.googleapis.com/auth/youtube',
            'refresh_token' => 'dummy-refresh',
            'access_token' => 'dummy-access',
        ],
        ['id' => 'dummy-channel', 'snippet' => ['title' => 'Dummy']],
    );
    $this->assertSame('https://www.googleapis.com/auth/youtube', $google->connection('u1')['scopes']);
});

test('deleting and recreating user preserves usage history', function (): void {
    $db = $this->database();
    $cfg = $this->config(['ALLOW_REGISTRATION' => 'true']);
    $g = new Google($cfg, $db, new Vault($cfg->get('APP_KEY')), new Client(), new Lock($this->dir));
    $identity = ['subject' => 'https://accounts.google.com|example', 'email' => 'person@example.test'];
    $first = $g->signIn($identity);
    $usage = new Usage($db);
    $usage->dispatch($first, 'videos.list');
    $db->execute('DELETE FROM users WHERE id=?', [$first]);
    $second = $g->signIn($identity);
    $this->assertSame($first, $second);
    $this->assertSame(1, $usage->status($second)['buckets']['general']['user_spent']);
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
                    ['access_token' => 'expired-' . $u, 'refresh_token' => 'refresh-' . $u, 'expires_at' => 0],
                ),
                Google::YOUTUBE_SCOPE,
                'g',
            ],
        );
    }
    $http = new class extends Client {
        public function request(string $method, string $url, array $headers = [], ?string $body = null): array
        {
            parse_str($body, $p);
            return [
                'status' => 200,
                'data' => ['access_token' => 'new-' . $p['refresh_token'], 'expires_in' => 3600],
            ];
        }
    };
    $g = new Google($cfg, $db, $v, $http, new Lock($this->dir));
    $this->assertSame('new-refresh-u1', $g->accessToken('u1'));
    $this->assertSame(
        'expired-u2',
        $v->decrypt(
            $db->one('SELECT tokens FROM google_connections WHERE user_id=?', ['u2'])['tokens'],
        )['access_token'],
    );
    $this->assertSame(
        'refresh-u1',
        $v->decrypt(
            $db->one('SELECT tokens FROM google_connections WHERE user_id=?', ['u1'])['tokens'],
        )['refresh_token'],
    );
});

test('invite only onboarding rejects unknown email', function (): void {
    $db = $this->database();
    $cfg = $this->config(['ALLOWED_EMAILS' => 'invited@example.test']);
    $g = new Google($cfg, $db, new Vault($cfg->get('APP_KEY')), new Client(), new Lock($this->dir));
    $this->expectException(ServiceError::class);
    $g->signIn(['subject' => 'issuer|other', 'email' => 'other@example.test']);
});

test('google refresh failures preserve credentials and independent mcp authorization', function (int $status, array $data, string $reason): void {
    $db = $this->database();
    $this->user($db);
    $cfg = $this->config();
    $vault = new Vault($cfg->get('APP_KEY'));
    $tokens = $vault->encrypt(['access_token' => 'expired', 'refresh_token' => 'dummy-refresh', 'expires_at' => 0]);
    $db->execute(
        'INSERT INTO google_connections VALUES (?,?,?,?,?,?)',
        ['u1', 'dummy-channel', 'Dummy', $tokens, Google::YOUTUBE_SCOPE, 'generation'],
    );
    $db->execute('INSERT INTO oauth_clients VALUES (?,?,?,?,?)', ['client', 'Dummy', '[]', null, 0]);
    foreach (['access', 'refresh'] as $kind) {
        $db->execute(
            'INSERT INTO oauth_tokens(id,kind,user_id,client_id,expires_at) VALUES (?,?,?,?,?)',
            [hash('sha256', $kind), $kind, 'u1', 'client', time() + 3600],
        );
    }
    $http = new class ($status, $data) extends Client {
        public function __construct(private int $status, private array $data) {}

        public function request(string $method, string $url, array $headers = [], ?string $body = null): array
        {
            return ['status' => $this->status, 'data' => $this->data];
        }
    };
    $google = new Google($cfg, $db, $vault, $http, new Lock($this->dir));

    try {
        $google->accessToken('u1');
        $this->fail('Refresh failure must be reported.');
    } catch (ServiceError $error) {
        $this->assertSame($reason, $error->reason);
    }

    $this->assertSame($tokens, $google->connection('u1')['tokens']);
    $this->assertSame('generation', $google->connection('u1')['generation']);
    $repository = new \YouTubeMcp\Auth\OAuthRepository($db, $cfg->url());
    $this->assertFalse($repository->isAccessTokenRevoked('access'));
    $this->assertFalse($repository->isRefreshTokenRevoked('refresh'));
})->with([
    'expired Google grant' => [400, ['error' => 'invalid_grant'], 'GOOGLE_RECONNECT_REQUIRED'],
    'invalid service credentials' => [401, ['error' => 'invalid_client'], 'GOOGLE_UNAVAILABLE'],
    'rejected service request' => [400, ['error' => 'invalid_request'], 'GOOGLE_UNAVAILABLE'],
    'rate limit' => [429, [], 'GOOGLE_UNAVAILABLE'],
    'Google outage' => [503, [], 'GOOGLE_UNAVAILABLE'],
]);

beforeEach(function (): void {
    $this->dir = $this->tempDir();
});

afterEach(function (): void {
    $this->removeDir($this->dir);
});
