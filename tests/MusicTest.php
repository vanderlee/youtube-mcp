<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use YouTubeMusicMcp\Auth\{Google, Vault};
use YouTubeMusicMcp\Http\Client;
use YouTubeMusicMcp\Music\{Api, Service, Tools};
use YouTubeMusicMcp\Operations\Receipts;
use YouTubeMusicMcp\ServiceError;
use YouTubeMusicMcp\Storage\Lock;

trait MusicTestHelpers
{
    private string $dir;

    private function service(array $responses): array
    {
        $db = $this->database();
        $cfg = $this->config();
        $locks = new Lock($this->dir);
        $vault = new Vault($cfg->get('APP_KEY'));
        foreach (['u1', 'u2'] as $user) {
            $this->user($db, $user);
            $db->execute('INSERT INTO google_connections VALUES (?,?,?,?,?,?)', [
                $user,
                'channel-' . $user,
                $user,
                $vault->encrypt(['access_token' => $user . '-token', 'expires_at' => time() + 3600]),
                Google::YOUTUBE_SCOPE . ' ' . Google::MUSIC_SCOPE,
                'generation'
            ]);
        }
        $google = new Google($cfg, $db, $vault, new Client(), $locks);
        $api = new class($responses) extends Api {
            public array $calls = [];

            public function __construct(private array $responses)
            {
            }

            public function call(string $tool, array $arguments, ?string $accessToken = null): mixed
            {
                $this->calls[] = [$tool, $arguments, $accessToken];
                if (!$this->responses) {
                    throw new \LogicException('Unexpected Music call');
                }
                $result = array_shift($this->responses);
                if ($result instanceof \Throwable) {
                    throw $result;
                }
                return $result;
            }
        };
        return [new Service($google, $api, new Receipts($db, $locks), $locks, $cfg), $api, $db];
    }
}

uses(MusicTestHelpers::class);

test('legacy google connections explain the missing music grant before upstream dispatch', function (): void {
    [$service, $api, $db] = $this->service([]);
    $db->execute('UPDATE google_connections SET scopes=? WHERE user_id=?', [Google::YOUTUBE_SCOPE, 'u1']);
    try {
        $service->execute('u1', ['history.read'], 'get_music_history', []);
        $this->fail();
    } catch (ServiceError $error) {
        $this->assertSame('GOOGLE_MUSIC_SCOPE_REQUIRED', $error->reason);
        $this->assertSame(Google::MUSIC_SCOPE, $error->details['required_google_scope']);
    }
    $this->assertSame([], $api->calls);
});

test('completed music receipts stay local even when google scope is subsequently missing', function (): void {
    [$service, $api, $db] = $this->service([['changed' => 1]]);
    $args = ['playlist_id' => 'PLdummy', 'in_library' => true, 'operation_key' => 'music-scope-replay'];
    $receipt = $service->execute('u1', ['library.write'], 'set_music_playlist_library_status', $args);
    $this->assertSame('completed', $receipt['status']);
    $db->execute('UPDATE google_connections SET scopes=? WHERE user_id=?', [Google::YOUTUBE_SCOPE, 'u1']);
    $this->assertSame($receipt, $service->execute('u1', ['library.write'], 'set_music_playlist_library_status', $args));
    $this->assertCount(1, $api->calls);
});

test('scopes and bounds fail before upstream', function (): void {
    [$service, $api] = $this->service([]);
    foreach (Tools::specs() as $tool => $spec) {
        try {
            $service->execute('u1', ['playlists.read', 'playlists.write'], $tool, []);
            $this->fail();
        } catch (ServiceError $e) {
            $this->assertSame('INSUFFICIENT_SERVICE_SCOPE', $e->reason);
        }
    }
    foreach (
        [
            ['query' => 'song', 'limit' => 51],
            ['query' => 'song', 'filter' => 'invalid'],
            ['query' => 'song', 'access_token' => 'injected']
        ] as $args
    ) {
        try {
            $service->execute('u1', ['catalog.read'], 'search_music', $args);
            $this->fail();
        } catch (ServiceError $e) {
            $this->assertSame('INVALID_ARGUMENT', $e->reason);
        }
    }
    $this->assertSame([], $api->calls);
});

test('reads use only the calling users google token and clamp nested lists', function (): void {
    [$service, $api] = $this->service([['tracks' => array_fill(0, 70, ['title' => 'Song'])], ['items' => []]]);
    $result = $service->execute('u1', ['catalog.read'], 'get_music_album', ['browse_id' => 'MPREtest']);
    $this->assertCount(50, $result['data']['tracks']);
    $this->assertTrue($result['nested_lists_may_be_truncated']);
    $service->execute('u2', ['history.read'], 'get_music_history', []);
    $this->assertSame('u1-token', $api->calls[0][2]);
    $this->assertSame('u2-token', $api->calls[1][2]);
});

test('library tokens cannot be reused for other users purposes or generations', function (): void {
    [$service, $api, $db] = $this->service(
        [['tracks' => [['feedbackTokens' => ['add' => 'raw-add', 'remove' => 'raw-remove']]]], []]
    );
    $data = $service->execute('u1', ['catalog.read'], 'get_music_album', ['browse_id' => 'MPREtest']);
    $token = $data['data']['tracks'][0]['feedbackTokens']['add'];
    $this->assertNotSame('raw-add', $token);
    $args = ['feedback_token' => $token, 'operation_key' => 'library-123'];
    $other = $service->execute('u2', ['library.write'], 'set_music_song_library_status', $args);
    $this->assertSame('INVALID_MUSIC_TOKEN', $other['error']['code']);
    $wrongPurpose = $service->execute(
        'u1',
        ['history.write'],
        'remove_music_history_items',
        ['feedback_tokens' => [$token], 'operation_key' => 'history-123']
    );
    $this->assertSame('INVALID_MUSIC_TOKEN', $wrongPurpose['error']['code']);
    $ok = $service->execute('u1', ['library.write'], 'set_music_song_library_status', $args);
    $this->assertSame('completed', $ok['status']);
    $this->assertSame('raw-add', $api->calls[1][1]['feedback_token']);
    $this->assertSame($ok, $service->execute('u1', ['library.write'], 'set_music_song_library_status', $args));
    $db->execute('UPDATE google_connections SET generation=? WHERE user_id=?', ['new-generation', 'u1']);
    $changed = $service->execute('u1', ['library.write'], 'set_music_song_library_status', $args);
    $this->assertSame('INVALID_MUSIC_TOKEN', $changed['error']['code']);
    $this->assertCount(2, $api->calls);
});

test('history removal uses issued token and uncertain write cannot resume', function (): void {
    [$service, $api] = $this->service([
        [['videoId' => 'aaaaaaaaaaa', 'feedbackToken' => 'history-token']],
        new ServiceError('UPSTREAM_UNCERTAIN', 'timeout')
    ]);
    $history = $service->execute('u1', ['history.read'], 'get_music_history', []);
    $args = ['feedback_tokens' => [$history['data'][0]['feedbackToken']], 'operation_key' => 'remove-history'];
    $receipt = $service->execute('u1', ['history.write'], 'remove_music_history_items', $args);
    $this->assertSame('uncertain', $receipt['status']);
    $this->assertSame(['history-token'], $api->calls[1][1]['feedback_tokens']);
    try {
        $service->execute('u1', ['history.write'], 'remove_music_history_items', $args + ['resume' => true]);
        $this->fail();
    } catch (ServiceError $e) {
        $this->assertSame('RECONCILIATION_REQUIRED', $e->reason);
    }
    $this->assertCount(2, $api->calls);
});

test('invalid and expired tokens never dispatch', function (): void {
    [$service, $api] = $this->service([]);
    $data = rtrim(
        strtr(
            base64_encode(
                json_encode(
                    [
                        'token' => 'raw',
                        'user' => 'u1',
                        'generation' => 'generation',
                        'purpose' => 'library',
                        'expires' => time() - 1
                    ]
                )
            ),
            '+/',
            '-_'
        ),
        '='
    );
    $signed = $data . '.' . rtrim(
            strtr(
                base64_encode(hash_hmac('sha256', 'music-action:' . $data, str_repeat('k', 32), true)),
                '+/',
                '-_'
            ),
            '='
        );
    foreach (['forged', $signed] as $n => $token) {
        $r = $service->execute(
            'u1',
            ['library.write'],
            'set_music_song_library_status',
            ['feedback_token' => $token, 'operation_key' => 'invalid-token-' . $n]
        );
        $this->assertSame('INVALID_MUSIC_TOKEN', $r['error']['code']);
    }
    $this->assertSame([], $api->calls);
});

test('music tool schemas and write annotations', function (): void {
    $definitions = array_column(\YouTubeMusicMcp\Mcp\Tools::definitions(), null, 'name');
    foreach (Tools::specs() as $name => $spec) {
        $json = json_decode(json_encode($definitions[$name]), true);
        $this->assertSame([Tools::scope($name)], $json['_meta']['securitySchemes'][0]['scopes']);
        $this->assertSame(!in_array($name, Tools::WRITES, true), $json['annotations']['readOnlyHint']);
    }
});

beforeEach(function (): void {
    $this->dir = $this->tempDir();
});

afterEach(function (): void {
    gc_collect_cycles();
    $this->removeDir($this->dir);
});
