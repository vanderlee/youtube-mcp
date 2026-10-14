<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use YouTubeMusicMcp\Auth\{Google, Vault};
use YouTubeMusicMcp\Http\Client;
use YouTubeMusicMcp\Operations\Receipts;
use YouTubeMusicMcp\Playlist\Service;
use YouTubeMusicMcp\Quota\Budget;
use YouTubeMusicMcp\ServiceError;
use YouTubeMusicMcp\Storage\Lock;
use YouTubeMusicMcp\YouTube\Api;

trait PlaylistTestHelpers
{
    private string $dir;

    private function service(array $responses, array $config = []): array
    {
        $db = $this->database();
        $this->user($db);
        $locks = new Lock($this->dir);
        $cfg = $this->config($config);
        $db->execute(
            'INSERT INTO google_connections VALUES (?,?,?,?,?,?)',
            ['u1', 'channel1', 'Test', 'unused', 'scope', 'generation1']
        );
        $google = new Google($cfg, $db, new Vault($cfg->get('APP_KEY')), new Client(), $locks);
        $api = new class($responses) extends Api {
            public array $calls = [];

            public function __construct(private array $responses)
            {
            }

            public function call(
                string $user,
                string $method,
                array $params = [],
                ?array $body = null,
                ?string $reservation = null,
                ?string $operation = null,
                ?string $token = null,
                int $ttl = 0
            ): array {
                $this->calls[] = [$method, $params, $body];
                $r = array_shift($this->responses);
                if ($r instanceof \Throwable) {
                    throw $r;
                }
                if ($r === null) {
                    throw new \LogicException('Unexpected API call ' . $method);
                }
                return $r;
            }
        };
        $service = new Service($google, $api, new Budget($db, $cfg), new Receipts($db, $locks), $locks);
        return [$service, $api];
    }

    private function metadata(string $channel = 'channel1'): array
    {
        return [
            'items' => [
                [
                    'id' => 'PLtest123456',
                    'snippet' => ['channelId' => $channel, 'title' => 'Original', 'description' => 'Keep me'],
                    'status' => ['privacyStatus' => 'private'],
                    'contentDetails' => ['itemCount' => 1000]
                ]
            ]
        ];
    }

    private function entry(string $id, string $video, int $position = 0): array
    {
        return [
            'id' => $id,
            'snippet' => [
                'playlistId' => 'PLtest123456',
                'position' => $position,
                'resourceId' => ['kind' => 'youtube#video', 'videoId' => $video]
            ]
        ];
    }
}

uses(PlaylistTestHelpers::class);

test('enhanced video search forwards validated filters without extra detail reads', function (): void {
    [$service, $api] = $this->service([['items' => [], 'nextPageToken' => 'next']]);
    $result = $service->execute('u1', ['playlists.read'], 'search_tracks', ['query' => 'fixture',
        'channel_id' => 'UC' . str_repeat('a', 22), 'published_after' => '2020-01-01T00:00:00Z',
        'published_before' => '2021-01-01T00:00:00Z', 'order' => 'date', 'duration' => 'short', 'category_id' => '10']);
    $this->assertCount(1, $api->calls);
    $this->assertSame('short', $api->calls[0][1]['videoDuration']);
    $this->assertSame('10', $api->calls[0][1]['videoCategoryId']);
    $this->assertSame('date', $api->calls[0][1]['order']);
    $this->assertSame('next', $result['next_page_token']);
    try { $service->execute('u1', ['playlists.read'], 'search_tracks', ['query' => 'fixture', 'duration' => 'invalid']); $this->fail(); }
    catch (ServiceError $error) { $this->assertSame('INVALID_ARGUMENT', $error->reason); }
    $this->assertCount(1, $api->calls);
});

test('connection diagnostics expose granted scope names without credentials or live access claims', function (): void {
    [$service, $api] = $this->service([]);
    $result = $service->execute('u1', ['playlists.read'], 'get_connection_status', []);
    $this->assertSame(['playlists.read'], $result['service_scopes']);
    $this->assertArrayNotHasKey('music_google_scope_granted', $result);
    $this->assertArrayNotHasKey('music_access_checked', $result);
    $this->assertArrayNotHasKey('tokens', $result);
    $this->assertSame([], $api->calls);
});

test('preference permissions do not follow playlist permissions', function (): void {
    [$s, $api] = $this->service([]);
    foreach (
        [
            'list_liked_tracks',
            'rate_track',
            'list_subscriptions',
            'subscribe_artist',
            'unsubscribe_artist'
        ] as $tool
    ) {
        try {
            $s->execute('u1', ['playlists.read', 'playlists.write'], $tool, []);
            $this->fail();
        } catch (ServiceError $e) {
            $this->assertSame('INSUFFICIENT_SERVICE_SCOPE', $e->reason);
        }
    }
    $this->assertSame([], $api->calls);
});

test('liked videos pagination and ratings', function (): void {
    [$s, $api] = $this->service(
        [
            ['items' => [], 'nextPageToken' => 'next'],
            ['items' => [['videoId' => 'aaaaaaaaaaa', 'rating' => 'like']]]
        ]
    );
    $r = $s->execute('u1', ['likes.read'], 'list_liked_tracks', ['page_token' => 'page', 'page_size' => 10]);
    $this->assertSame('next', $r['nextPageToken']);
    $this->assertSame('like', $api->calls[0][1]['myRating']);
    $this->assertSame('page', $api->calls[0][1]['pageToken']);
    $s->execute(
        'u1',
        ['likes.read'],
        'get_track_ratings',
        ['video_ids' => ['https://music.youtube.com/watch?v=aaaaaaaaaaa']]
    );
    $this->assertSame(['id' => 'aaaaaaaaaaa'], $api->calls[1][1]);
});

test('rating replay and removal', function (): void {
    [$s, $api] = $this->service([[]]);
    $args = ['video_id' => 'aaaaaaaaaaa', 'rating' => 'none', 'operation_key' => 'rate-1234'];
    $r = $s->execute('u1', ['likes.write'], 'rate_track', $args);
    $this->assertSame('completed', $r['status']);
    $this->assertSame($r, $s->execute('u1', ['likes.write'], 'rate_track', $args));
    $this->assertCount(1, $api->calls);
    $this->assertSame(['videos.rate', ['id' => 'aaaaaaaaaaa', 'rating' => 'none'], null], $api->calls[0]);
});

test('subscriptions resolve owned id and skip existing', function (): void {
    $channel = 'UC' . str_repeat('a', 22);
    [$s, $api] = $this->service([['items' => [['id' => 'owned-sub']]], ['items' => [['id' => 'owned-sub']]], []]);
    $r = $s->execute(
        'u1',
        ['subscriptions.write'],
        'subscribe_artist',
        ['channel_id' => $channel, 'operation_key' => 'sub-1234']
    );
    $this->assertSame(0, $r['result']['changed']);
    $r = $s->execute(
        'u1',
        ['subscriptions.write'],
        'unsubscribe_artist',
        ['channel_id' => $channel, 'operation_key' => 'unsub-1234']
    );
    $this->assertSame('completed', $r['status']);
    $this->assertSame('true', $api->calls[1][1]['mine']);
    $this->assertSame($channel, $api->calls[1][1]['forChannelId']);
    $this->assertSame(['subscriptions.delete', ['id' => 'owned-sub'], null], $api->calls[2]);
});

test('subscription creation and absent removal', function (): void {
    $channel = 'UC' . str_repeat('b', 22);
    [$s, $api] = $this->service([['items' => []], ['id' => 'new-sub'], ['items' => []]]);
    $s->execute(
        'u1',
        ['subscriptions.write'],
        'subscribe_artist',
        ['channel_id' => $channel, 'operation_key' => 'sub-1234']
    );
    $this->assertSame($channel, $api->calls[1][2]['snippet']['resourceId']['channelId']);
    $r = $s->execute(
        'u1',
        ['subscriptions.write'],
        'unsubscribe_artist',
        ['channel_id' => $channel, 'operation_key' => 'unsub-1234']
    );
    $this->assertSame(0, $r['result']['changed']);
    $this->assertCount(3, $api->calls);
});

test('write scope enforced before any api call', function (): void {
    [$s, $api] = $this->service([]);
    try {
        $s->execute(
            'u1',
            ['playlists.read'],
            'delete_playlist',
            ['playlist_id' => 'PLtest123456', 'operation_key' => 'key-1234']
        );
        $this->fail();
    } catch (ServiceError $e) {
        $this->assertSame('INSUFFICIENT_SERVICE_SCOPE', $e->reason);
    }
    $this->assertSame([], $api->calls);
});

test('ownership rejected before mutation', function (): void {
    [$s, $api] = $this->service([$this->metadata('another-channel')]);
    $r = $s->execute(
        'u1',
        ['playlists.write'],
        'delete_playlist',
        ['playlist_id' => 'PLtest123456', 'operation_key' => 'key-1234']
    );
    $this->assertSame('NOT_PLAYLIST_OWNER', $r['error']['code']);
    $this->assertCount(1, $api->calls);
});

test('update preserves omitted description and privacy', function (): void {
    [$s, $api] = $this->service([$this->metadata(), ['id' => 'PLtest123456']]);
    $r = $s->execute(
        'u1',
        ['playlists.write'],
        'update_playlist',
        ['playlist_id' => 'PLtest123456', 'title' => 'New', 'operation_key' => 'key-1234']
    );
    $this->assertSame('completed', $r['status']);
    $body = $api->calls[1][2];
    $this->assertSame('Keep me', $body['snippet']['description']);
    $this->assertSame('private', $body['status']['privacyStatus']);
});

test('duplicate policy skips existing and repeated videos', function (): void {
    [$s, $api] = $this->service(
        [$this->metadata(), ['items' => [$this->entry('entry1', 'aaaaaaaaaaa')]], ['id' => 'entry2']]
    );
    $r = $s->execute(
        'u1',
        ['playlists.write'],
        'add_tracks',
        [
            'playlist_id' => 'PLtest123456',
            'video_ids' => ['aaaaaaaaaaa', 'https://music.youtube.com/watch?v=bbbbbbbbbbb', 'bbbbbbbbbbb'],
            'operation_key' => 'key-1234'
        ]
    );
    $this->assertSame('completed', $r['status']);
    $this->assertCount(3, $api->calls);
    $this->assertSame('bbbbbbbbbbb', $api->calls[2][2]['snippet']['resourceId']['videoId']);
});

test('remove targets occurrence rather than video', function (): void {
    [$s, $api] = $this->service(
        [
            $this->metadata(),
            ['items' => [$this->entry('first', 'aaaaaaaaaaa'), $this->entry('second', 'aaaaaaaaaaa', 1)]],
            []
        ]
    );
    $r = $s->execute(
        'u1',
        ['playlists.write'],
        'remove_tracks',
        ['playlist_id' => 'PLtest123456', 'playlist_item_ids' => ['second'], 'operation_key' => 'key-1234']
    );
    $this->assertSame('completed', $r['status']);
    $this->assertSame(['id' => 'second'], $api->calls[2][1]);
});

test('legacy budget does not block batch writes', function (): void {
    [$s, $api] = $this->service([$this->metadata(), [], []], ['USER_QUOTA_GENERAL' => '50']);
    $r = $s->execute(
        'u1',
        ['playlists.write'],
        'add_tracks',
        [
            'playlist_id' => 'PLtest123456',
            'video_ids' => ['aaaaaaaaaaa', 'bbbbbbbbbbb'],
            'duplicate_policy' => 'allow',
            'operation_key' => 'key-1234'
        ]
    );
    $this->assertSame('completed', $r['status']);
    $this->assertCount(3, $api->calls);
});

test('successful mutation replay consumes no api requests', function (): void {
    [$s, $api] = $this->service([['id' => 'PLtest123456']]);
    $args = ['title' => 'Test', 'operation_key' => 'key-1234'];
    $first = $s->execute('u1', ['playlists.write'], 'create_playlist', $args);
    $second = $s->execute('u1', ['playlists.write'], 'create_playlist', $args);
    $this->assertSame($first, $second);
    $this->assertCount(1, $api->calls);
});

test('create with tracks uses one creation and only necessary insertions', function (): void {
    [$s, $api] = $this->service([['id' => 'PLtest123456'], ['id' => 'item1'], ['id' => 'item2']]);
    $a = [
        'title' => 'Test',
        'video_ids' => ['aaaaaaaaaaa', 'aaaaaaaaaaa', 'bbbbbbbbbbb'],
        'operation_key' => 'create-1234'
    ];
    $r = $s->execute('u1', ['playlists.write'], 'create_playlist', $a);
    $this->assertSame('completed', $r['status']);
    $this->assertSame(2, $r['result']['added']);
    $this->assertSame(1, $r['result']['skipped']);
    $this->assertSame(['playlists.insert', 'playlistItems.insert', 'playlistItems.insert'],
        array_column($api->calls, 0));
    $this->assertSame('private', $api->calls[0][2]['status']['privacyStatus']);
    $this->assertSame($r, $s->execute('u1', ['playlists.write'], 'create_playlist', $a));
    $this->assertCount(3, $api->calls);
});

test('create validates all tracks before creating playlist', function (): void {
    [$s, $api] = $this->service([]);
    $r = $s->execute(
        'u1',
        ['playlists.write'],
        'create_playlist',
        ['title' => 'Test', 'video_ids' => ['aaaaaaaaaaa', 'bad'], 'operation_key' => 'create-1234']
    );
    $this->assertSame('partial', $r['status']);
    $this->assertSame([], $api->calls);
});

test('create resume does not recreate playlist or replay inserted tracks', function (): void {
    [$s, $api] = $this->service([
        ['id' => 'PLtest123456'],
        ['id' => 'item1'],
        new ServiceError('YOUTUBE_QUOTA_EXHAUSTED', 'wait'),
        $this->metadata(),
        ['id' => 'item2']
    ]);
    $a = ['title' => 'Test', 'video_ids' => ['aaaaaaaaaaa', 'bbbbbbbbbbb'], 'operation_key' => 'create-1234'];
    $r = $s->execute('u1', ['playlists.write'], 'create_playlist', $a);
    $this->assertSame('partial', $r['status']);
    $r = $s->execute('u1', ['playlists.write'], 'create_playlist', $a + ['resume' => true]);
    $this->assertSame('completed', $r['status']);
    $this->assertSame(1, count(array_filter($api->calls, fn($c) => $c[0] === 'playlists.insert')));
    $this->assertSame('bbbbbbbbbbb', $api->calls[4][2]['snippet']['resourceId']['videoId']);
});

test('move uses targeted lookup even on large playlist', function (): void {
    [$s, $api] = $this->service(
        [$this->metadata(), ['items' => [$this->entry('entry1', 'aaaaaaaaaaa')]], ['id' => 'entry1']]
    );
    $r = $s->execute(
        'u1',
        ['playlists.write'],
        'move_track',
        [
            'playlist_id' => 'PLtest123456',
            'playlist_item_id' => 'entry1',
            'position' => 900,
            'operation_key' => 'move-1234'
        ]
    );
    $this->assertSame('completed', $r['status']);
    $this->assertSame('entry1', $api->calls[1][1]['id']);
    $this->assertArrayNotHasKey('playlistId', $api->calls[1][1]);
    $this->assertCount(3, $api->calls);
});

test('foreign entry rejected before removal', function (): void {
    $entry = $this->entry('entry1', 'aaaaaaaaaaa');
    $entry['snippet']['playlistId'] = 'PLanother123';
    [$s, $api] = $this->service([$this->metadata(), ['items' => [$entry]]]);
    $r = $s->execute(
        'u1',
        ['playlists.write'],
        'remove_tracks',
        ['playlist_id' => 'PLtest123456', 'playlist_item_ids' => ['entry1'], 'operation_key' => 'remove-1234']
    );
    $this->assertSame('ITEM_NOT_IN_PLAYLIST', $r['error']['code']);
    $this->assertCount(2, $api->calls);
});

test('search enrichment is optional', function (): void {
    $search = [
        'items' => [
            [
                'id' => ['videoId' => 'aaaaaaaaaaa'],
                'snippet' => ['title' => 'Song', 'channelTitle' => 'Artist']
            ]
        ],
        'nextPageToken' => 'next'
    ];
    [$s, $api] = $this->service(
        [
            $search,
            $search,
            [
                'items' => [
                    [
                        'id' => 'aaaaaaaaaaa',
                        'snippet' => ['title' => 'Song', 'channelTitle' => 'Artist'],
                        'contentDetails' => ['duration' => 'PT3M']
                    ]
                ]
            ]
        ]
    );
    $r = $s->execute('u1', ['playlists.read'], 'search_tracks', ['query' => 'song']);
    $this->assertCount(1, $api->calls);
    $this->assertSame('next', $r['next_page_token']);
    $this->assertArrayNotHasKey('duration', $r['candidates'][0]);
    $r = $s->execute('u1', ['playlists.read'], 'search_tracks', ['query' => 'song', 'include_details' => true]);
    $this->assertCount(3, $api->calls);
    $this->assertSame('PT3M', $r['candidates'][0]['duration']);
});

test('uncertain create with tracks cannot resume', function (): void {
    [$s, $api] = $this->service([['id' => 'PLtest123456'], new ServiceError('UPSTREAM_UNCERTAIN', 'timeout')]);
    $a = ['title' => 'Test', 'video_ids' => ['aaaaaaaaaaa'], 'operation_key' => 'create-1234'];
    $this->assertSame('uncertain', $s->execute('u1', ['playlists.write'], 'create_playlist', $a)['status']);
    try {
        $s->execute('u1', ['playlists.write'], 'create_playlist', $a + ['resume' => true]);
        $this->fail();
    } catch (ServiceError $e) {
        $this->assertSame('RECONCILIATION_REQUIRED', $e->reason);
    }
    $this->assertCount(2, $api->calls);
});

beforeEach(function (): void {
    $this->dir = $this->tempDir();
});

afterEach(function (): void {
    gc_collect_cycles();
    $this->removeDir($this->dir);
});
