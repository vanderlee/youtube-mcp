<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use YouTubeMusicMcp\Auth\{Google, MusicBrowser, MusicBrowserCredentials, Vault};
use YouTubeMusicMcp\Http\Client;
use YouTubeMusicMcp\Music\Api;
use YouTubeMusicMcp\ServiceError;
use YouTubeMusicMcp\Storage\Lock;

test('browser credentials reject injection and generate fresh origin bound authorization', function (): void {
    $credentials = MusicBrowserCredentials::parse('__Secure-3PAPISID=dummy; SID=other', '1');
    $headers = MusicBrowserCredentials::headers($credentials, 123);
    $this->assertContains('Authorization: SAPISIDHASH 123_' . sha1('123 dummy https://music.youtube.com'), $headers);
    $this->assertContains('X-Goog-AuthUser: 1', $headers);
    $this->assertNotSame($headers, MusicBrowserCredentials::headers($credentials, 124));
    foreach ([['SID=dummy', '0'], ["SAPISID=dummy\r\nInjected: x", '0'], ['SAPISID=a; SAPISID=b', '0'], ['SAPISID=a', '0\r\n']] as [$cookie, $index]) {
        try { MusicBrowserCredentials::parse($cookie, $index); $this->fail('Unsafe input accepted'); }
        catch (ServiceError $error) { $this->assertSame('INVALID_MUSIC_BROWSER_CREDENTIALS', $error->reason); }
    }
});

test('browser connections verify exact channel encrypt credentials and isolate users and generations', function (): void {
    $dir = $this->tempDir();
    try {
        $db = $this->database();
        $cfg = $this->config();
        $vault = new Vault($cfg->get('APP_KEY'));
        $locks = new Lock($dir);
        foreach (['u1', 'u2'] as $user) {
            $this->user($db, $user);
            $db->execute('INSERT INTO google_connections VALUES (?,?,?,?,?,?)', [$user, 'channel-' . $user, 'Fixture',
                $vault->encrypt(['access_token' => 'dummy', 'expires_at' => time() + 3600]), Google::MUSIC_SCOPE, 'generation-' . $user]);
        }
        $http = new class extends Client {
            public function request(string $method, string $url, array $headers = [], ?string $body = null): array { throw new \LogicException('No real HTTP'); }
        };
        $google = new Google($cfg, $db, $vault, $http, $locks);
        $youtube = new class extends \YouTubeMusicMcp\YouTube\Api {
            public string $channel = 'wrong';
            public function __construct() {}
            public function call(string $user, string $method, array $params = [], ?array $body = null, ?string $reservation = null, ?string $operation = null, ?string $token = null, int $ttl = 0): array {
                if ($params !== ['part' => 'id', 'forHandle' => '@fixture']) { throw new \LogicException('Wrong identity check'); }
                return ['items' => [['id' => $this->channel]]];
            }
        };
        $api = new class($cfg) extends Api {
            public string $handle = '@fixture';
            public array $calls = [];
            public function withBrowser(array $credentials): Api { return $this; }
            public function call(string $tool, array $a, ?string $accessToken = null): mixed {
                $this->calls[] = [$tool, $accessToken];
                if ($tool !== 'get_music_account') { return ['items' => []]; }
                return ['channelHandle' => $this->handle];
            }
        };
        $browser = new MusicBrowser($db, $vault, $google, $youtube, $api, $locks);
        try { $browser->connect('u1', 'SAPISID=secret-fixture', '0'); $this->fail('Wrong channel accepted'); }
        catch (ServiceError $error) { $this->assertSame('MUSIC_CHANNEL_MISMATCH', $error->reason); }
        $this->assertNull($browser->connection('u1', $google->connection('u1')));
        $youtube->channel = 'channel-u1';
        $db->execute('INSERT INTO oauth_tokens VALUES (?,?,?,?,?,?)', ['browser-token-test', 'access', 'u1', 'dummy-client', time() + 3600, 0]);
        $browser->connect('u1', 'SAPISID=secret-fixture', '0');
        $this->assertSame(1, (int)$db->one('SELECT revoked FROM oauth_tokens WHERE id=?', ['browser-token-test'])['revoked']);
        $row = $browser->connection('u1', $google->connection('u1'));
        $this->assertStringNotContainsString('secret-fixture', $row['credentials']);
        $this->assertNull($browser->connection('u2', $google->connection('u2')));
        $db->execute('UPDATE music_browser_connections SET expires_at=0 WHERE user_id=?', ['u1']);
        try { $browser->connection('u1', $google->connection('u1')); $this->fail('Expired session accepted'); }
        catch (ServiceError $error) { $this->assertSame('MUSIC_BROWSER_RECONNECT_REQUIRED', $error->reason); }
        $db->execute('UPDATE music_browser_connections SET expires_at=? WHERE user_id=?', [time() + 3600, 'u1']);
        $this->assertSame($api, $browser->client($row));
        $service = new \YouTubeMusicMcp\Music\Service($google, $api,
            new \YouTubeMusicMcp\Operations\Receipts($db, $locks), $locks, $cfg, null, $browser);
        $beforeCalls = count($api->calls);
        try { $service->execute('u1', [], 'get_music_history', []); $this->fail('Scope bypass'); }
        catch (ServiceError $error) { $this->assertSame('INSUFFICIENT_SERVICE_SCOPE', $error->reason); }
        $this->assertCount($beforeCalls, $api->calls);
        $result = $service->execute('u1', ['history.read'], 'get_music_history', []);
        $this->assertSame('youtube_music_unofficial', $result['source']);
        $this->assertSame(['get_music_history', null], $api->calls[array_key_last($api->calls)]);
        $arguments = ['playlist_id' => 'PLdummy', 'in_library' => true, 'operation_key' => 'browser-write-test'];
        $service->execute('u1', ['library.write'], 'set_music_playlist_library_status', $arguments);
        $afterWrite = count($api->calls);
        $service->execute('u1', ['library.write'], 'set_music_playlist_library_status', $arguments);
        $this->assertCount($afterWrite, $api->calls, 'Completed browser writes must replay locally.');
        $this->assertStringContainsString(':browser:' . $row['generation'], $db->one('SELECT generation FROM operations WHERE user_id=?', ['u1'])['generation']);
        $api->handle = '@another';
        try { $browser->client($row); $this->fail('Changed identity accepted'); }
        catch (ServiceError $error) { $this->assertSame('MUSIC_BROWSER_RECONNECT_REQUIRED', $error->reason); }
        $db->execute('UPDATE google_connections SET generation=? WHERE user_id=?', ['new', 'u1']);
        try { $browser->connection('u1', $google->connection('u1')); $this->fail('Stale generation accepted'); }
        catch (ServiceError $error) { $this->assertSame('MUSIC_BROWSER_RECONNECT_REQUIRED', $error->reason); }
        $browser->disconnect('u1');
        $this->assertNull($db->one('SELECT user_id FROM music_browser_connections WHERE user_id=?', ['u1']));
    } finally { unset($db, $browser, $google); gc_collect_cycles(); $this->removeDir($dir); }
});
