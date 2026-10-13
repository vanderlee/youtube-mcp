<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use YouTubeMusicMcp\Auth\{Google, MusicOAuth, Vault};
use YouTubeMusicMcp\Http\Client;
use YouTubeMusicMcp\Music\{Api as MusicApi, Service as MusicService};
use YouTubeMusicMcp\Operations\Receipts;
use YouTubeMusicMcp\ServiceError;
use YouTubeMusicMcp\Storage\Lock;
use YouTubeMusicMcp\YouTube\Api;

trait MusicOAuthFixtures
{
    private string $dir;

    private function environment(array $responses, string $authorizedChannel = 'channel-u1'): array
    {
        $db = $this->database();
        $cfg = $this->config(['MUSIC_OAUTH_CLIENT_ID' => 'dummy-device-client', 'MUSIC_OAUTH_CLIENT_SECRET' => 'dummy-device-secret']);
        $vault = new Vault($cfg->get('APP_KEY'));
        $locks = new Lock($this->dir);
        foreach (['u1', 'u2'] as $user) {
            $this->user($db, $user);
            $db->execute('INSERT INTO google_connections VALUES (?,?,?,?,?,?)', [$user, 'channel-' . $user, 'Fixture',
                $vault->encrypt(['access_token' => 'web-' . $user, 'expires_at' => time() + 3600]), Google::YOUTUBE_SCOPE, 'google-' . $user]);
        }
        $http = new class($responses) extends Client {
            public array $calls = [];
            public function __construct(private array $responses) {}
            public function request(string $method, string $url, array $headers = [], ?string $body = null): array {
                parse_str($body ?? '', $params);
                $this->calls[] = [$method, $url, $params];
                $response = array_shift($this->responses);
                if ($response === null) { throw new \LogicException('Unexpected request'); }
                return $response;
            }
        };
        $youtube = new class($authorizedChannel) extends Api {
            public array $calls = [];
            public function __construct(private string $channel) {}
            public function call(string $user, string $method, array $params = [], ?array $body = null,
                ?string $reservation = null, ?string $operation = null, ?string $token = null, int $ttl = 0): array {
                $this->calls[] = [$user, $method, $token];
                return ['items' => [['id' => $this->channel]]];
            }
        };
        $google = new Google($cfg, $db, $vault, $http, $locks);
        $provider = new MusicOAuth($cfg, $db, $google, $vault, $http, $youtube, $locks);
        return [$provider, $db, $http, $youtube, $cfg, $google, $locks, $vault];
    }

    private function codeResponse(string $url = 'https://www.google.com/device'): array
    {
        return ['status' => 200, 'data' => ['device_code' => 'private-device-code', 'user_code' => 'ABCD-EFGH',
            'verification_url' => $url, 'expires_in' => 1800, 'interval' => 5]];
    }

    private function tokenResponse(): array
    {
        return ['status' => 200, 'data' => ['access_token' => 'device-access', 'refresh_token' => 'device-refresh',
            'expires_in' => 3600, 'scope' => Google::MUSIC_SCOPE, 'token_type' => 'Bearer']];
    }
}

uses(MusicOAuthFixtures::class);
beforeEach(function (): void { $this->dir = $this->tempDir(); });
afterEach(function (): void { gc_collect_cycles(); $this->removeDir($this->dir); });

test('missing music migration stops authorization before requesting or consuming a device grant', function (): void {
    [$provider, $db, $http] = $this->environment([]);
    $db->execute('DROP TABLE music_connections');
    foreach (['begin', 'poll'] as $method) {
        try {
            $flow = [];
            $method === 'begin' ? $provider->begin('u1') : $provider->poll('u1', $flow);
            $this->fail('Uninitialized Music storage must stop authorization.');
        } catch (ServiceError $error) {
            $this->assertSame('DEPLOYMENT_MIGRATION_REQUIRED', $error->reason);
            $this->assertSame(503, $error->httpStatus);
        }
    }
    $this->assertSame([], $http->calls);
});

test('database diagnostics identify missing music storage without exposing driver messages', function (): void {
    $db = $this->database();
    try {
        $db->execute('SELECT * FROM missing_private_table');
        $this->fail('Expected a database exception.');
    } catch (\PDOException $error) {
        $details = \YouTubeMusicMcp\Storage\Database::errorDetails($error);
        $this->assertSame('missing_table', $details['database_reason']);
        $this->assertSame('HY000', $details['database_sqlstate']);
        $this->assertArrayNotHasKey('database_table', $details);
        $this->assertStringNotContainsString('missing_private_table', json_encode($details));
    }
    $db->execute('DROP TABLE music_connections');
    try { $db->execute('SELECT * FROM music_connections'); }
    catch (\PDOException $error) {
        $this->assertSame('music_connections', \YouTubeMusicMcp\Storage\Database::errorDetails($error)['database_table']);
    }
});

test('device polling verifies the channel through the real youtube transport and usage storage', function (): void {
    [$unused, $db, $http, $unusedApi, $cfg, $google, $locks, $vault] = $this->environment([
        $this->codeResponse(), $this->tokenResponse(),
        ['status' => 200, 'data' => ['items' => [['id' => 'channel-u1']]]],
    ]);
    $youtube = new Api($http, new \YouTubeMusicMcp\Quota\Budget($db, $cfg), $db, $google);
    $provider = new MusicOAuth($cfg, $db, $google, $vault, $http, $youtube, $locks);
    $flow = $provider->begin('u1');
    $flow['next_poll_at'] = 0;
    $this->assertTrue($provider->poll('u1', $flow));
    $this->assertSame('GET', $http->calls[2][0]);
    $this->assertStringStartsWith('https://www.googleapis.com/youtube/v3/channels?', $http->calls[2][1]);
    $this->assertSame('success', $db->one('SELECT state FROM quota_requests WHERE user_id=?', ['u1'])['state']);
    $this->assertSame('channel-u1', $provider->connection('u1', $google->connection('u1'))['channel_id']);
});

test('device flow encrypts codes respects poll intervals and binds consent to the selected channel', function (): void {
    [$provider, $db, $http, $youtube] = $this->environment([$this->codeResponse(), $this->tokenResponse()]);
    $flow = $provider->begin('u1');
    $this->assertSame('https://oauth2.googleapis.com/device/code', $http->calls[0][1]);
    $this->assertSame(Google::MUSIC_SCOPE, $http->calls[0][2]['scope']);
    $this->assertStringNotContainsString('private-device-code', json_encode($flow));
    $this->assertFalse($provider->poll('u1', $flow));
    $this->assertCount(1, $http->calls);
    $flow['next_poll_at'] = 0;
    $this->assertTrue($provider->poll('u1', $flow));
    $this->assertSame('urn:ietf:params:oauth:grant-type:device_code', $http->calls[1][2]['grant_type']);
    $this->assertSame(['u1', 'channels.list', 'device-access'], $youtube->calls[0]);
    $row = $db->one('SELECT * FROM music_connections WHERE user_id=?', ['u1']);
    $this->assertSame('channel-u1', $row['channel_id']);
    $this->assertStringNotContainsString('device-access', $row['tokens']);
    $this->assertNull($db->one('SELECT * FROM music_connections WHERE user_id=?', ['u2']));
});

test('device consent for a different channel is never persisted', function (): void {
    [$provider, $db] = $this->environment([$this->codeResponse(), $this->tokenResponse()], 'another-channel');
    $flow = $provider->begin('u1');
    $flow['next_poll_at'] = 0;
    try { $provider->poll('u1', $flow); $this->fail(); }
    catch (ServiceError $error) { $this->assertSame('MUSIC_CHANNEL_MISMATCH', $error->reason); }
    $this->assertSame([], $db->all('SELECT * FROM music_connections'));
});

test('pending device consent and slow down never trigger extra automatic polls', function (): void {
    [$provider, , $http] = $this->environment([$this->codeResponse(),
        ['status' => 400, 'data' => ['error' => 'authorization_pending']],
        ['status' => 400, 'data' => ['error' => 'slow_down']]]);
    $flow = $provider->begin('u1');
    $flow['next_poll_at'] = 0;
    $this->assertFalse($provider->poll('u1', $flow));
    $flow['next_poll_at'] = 0;
    $this->assertFalse($provider->poll('u1', $flow));
    $this->assertSame(10, $flow['interval']);
    $this->assertFalse($provider->poll('u1', $flow));
    $this->assertCount(3, $http->calls);
});

test('device flow cannot cross users or google reconnect generations', function (): void {
    [$provider, $db, $http] = $this->environment([$this->codeResponse()]);
    $flow = $provider->begin('u1');
    foreach (['u2', 'u1'] as $user) {
        if ($user === 'u1') { $db->execute('UPDATE google_connections SET generation=? WHERE user_id=?', ['changed', 'u1']); }
        $flow['next_poll_at'] = 0;
        try { $provider->poll($user, $flow); $this->fail(); }
        catch (ServiceError $error) { $this->assertSame('MUSIC_AUTHORIZATION_EXPIRED', $error->reason); }
    }
    $this->assertCount(1, $http->calls);
});

test('device token is used only for music and refresh is isolated to that connection', function (): void {
    [$provider, $db, $http, , $cfg, $google, $locks, $vault] = $this->environment([
        $this->codeResponse(), $this->tokenResponse(), ['status' => 200, 'data' => ['access_token' => 'refreshed-device', 'expires_in' => 3600]],
    ]);
    $flow = $provider->begin('u1');
    $flow['next_poll_at'] = 0;
    $provider->poll('u1', $flow);
    $api = new class extends MusicApi {
        public array $calls = [];
        public function __construct() {}
        public function call(string $tool, array $arguments, ?string $accessToken = null): mixed {
            $this->calls[] = [$tool, $accessToken]; return ['items' => []];
        }
    };
    $service = new MusicService($google, $api, new Receipts($db, $locks), $locks, $cfg, $provider);
    $service->execute('u1', ['history.read'], 'get_music_history', []);
    $this->assertSame(['get_music_history', 'device-access'], $api->calls[0]);
    $connection = $provider->connection('u1', $google->connection('u1'));
    $tokens = $vault->decrypt($connection['tokens']);
    $tokens['expires_at'] = 0;
    $db->execute('UPDATE music_connections SET tokens=? WHERE user_id=?', [$vault->encrypt($tokens), 'u1']);
    $service->execute('u1', ['history.read'], 'get_music_history', []);
    $this->assertSame(['get_music_history', 'refreshed-device'], $api->calls[1]);
    $this->assertSame('web-u1', $google->accessToken('u1'));
    $this->assertSame('dummy-device-client', $http->calls[2][2]['client_id']);
    $this->assertSame('web-u2', $google->accessToken('u2'));
});

test('configured device oauth requires a separate connection instead of silently reusing web tokens', function (): void {
    [$provider, $db, , , $cfg, $google, $locks] = $this->environment([]);
    $api = new class extends MusicApi { public function __construct() {} };
    $service = new MusicService($google, $api, new Receipts($db, $locks), $locks, $cfg, $provider);
    try { $service->execute('u1', ['history.read'], 'get_music_history', []); $this->fail(); }
    catch (ServiceError $error) { $this->assertSame('MUSIC_CONNECT_REQUIRED', $error->reason); }
    $this->assertSame('device_oauth', $provider->status('u1', $google->connection('u1'))['music_authentication']);
});

test('unsafe verification urls and incomplete configuration fail closed', function (): void {
    [$provider] = $this->environment([$this->codeResponse('https://evil.test/collect')]);
    try { $provider->begin('u1'); $this->fail(); }
    catch (ServiceError $error) { $this->assertSame('MUSIC_OAUTH_SETUP_REQUIRED', $error->reason); }
    $this->expectException(\RuntimeException::class);
    $this->config(['MUSIC_OAUTH_CLIENT_ID' => 'dummy-client'])->validate();
});

test('music migration reruns preserve connections and account deletion cascades', function (): void {
    [$provider, $db] = $this->environment([$this->codeResponse(), $this->tokenResponse()]);
    $flow = $provider->begin('u1'); $flow['next_poll_at'] = 0; $provider->poll('u1', $flow);
    $db->migrate(dirname(__DIR__));
    $this->assertSame(1, (int)$db->one('SELECT COUNT(*) AS n FROM music_connections')['n']);
    $db->execute('DELETE FROM users WHERE id=?', ['u1']);
    $this->assertSame([], $db->all('SELECT * FROM music_connections'));
});

test('replacing music authorization revokes service tokens and invalidates old action tokens', function (): void {
    [$provider, $db, , , $cfg, $google, $locks] = $this->environment([
        $this->codeResponse(), $this->tokenResponse(), $this->codeResponse(), $this->tokenResponse(),
    ]);
    $flow = $provider->begin('u1'); $flow['next_poll_at'] = 0; $provider->poll('u1', $flow);
    $api = new class extends MusicApi {
        public int $calls = 0;
        public function __construct() {}
        public function call(string $tool, array $arguments, ?string $accessToken = null): mixed {
            $this->calls++; return ['tracks' => [['feedbackTokens' => ['add' => 'raw-action']]]];
        }
    };
    $service = new MusicService($google, $api, new Receipts($db, $locks), $locks, $cfg, $provider);
    $data = $service->execute('u1', ['catalog.read'], 'get_music_album', ['browse_id' => 'MPREfixture']);
    $oldToken = $data['data']['tracks'][0]['feedbackTokens']['add'];
    $db->execute('INSERT INTO oauth_tokens VALUES (?,?,?,?,?,?)', ['dummy-service-token', 'access', 'u1', 'dummy-client', time() + 3600, 0]);
    $flow = $provider->begin('u1'); $flow['next_poll_at'] = 0; $provider->poll('u1', $flow);
    $this->assertSame(1, (int)$db->one('SELECT revoked FROM oauth_tokens WHERE id=?', ['dummy-service-token'])['revoked']);
    $result = $service->execute('u1', ['library.write'], 'set_music_song_library_status',
        ['feedback_token' => $oldToken, 'operation_key' => 'old-music-action']);
    $this->assertSame('INVALID_MUSIC_TOKEN', $result['error']['code']);
    $this->assertSame(1, $api->calls);
});

test('official google reconnect removes only the matching users music credentials', function (): void {
    [$provider, $db, , , , $google] = $this->environment([$this->codeResponse(), $this->tokenResponse()]);
    $flow = $provider->begin('u1'); $flow['next_poll_at'] = 0; $provider->poll('u1', $flow);
    $google->connect('u1', ['scope' => Google::YOUTUBE_SCOPE, 'access_token' => 'new-web', 'refresh_token' => 'new-refresh'],
        ['id' => 'channel-u1', 'snippet' => ['title' => 'Fixture']]);
    $this->assertNull($db->one('SELECT * FROM music_connections WHERE user_id=?', ['u1']));
    $this->assertSame('google-u2', $google->connection('u2')['generation']);
});
