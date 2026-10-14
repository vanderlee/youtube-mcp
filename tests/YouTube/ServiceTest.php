<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\YouTube;

use YouTubeMcp\Auth\{Google, Vault};
use YouTubeMcp\Http\Client;
use YouTubeMcp\Operations\Receipts;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Lock;
use YouTubeMcp\YouTube\{Api, Service};

covers(\YouTubeMcp\YouTube\Service::class);

trait ExtendedYouTubeFixtures
{
    private string $dir;

    private function extended(array $responses): array
    {
        $db = $this->database();
        $this->user($db);
        $cfg = $this->config();
        $db->execute(
            'INSERT INTO google_connections VALUES (?,?,?,?,?,?)',
            ['u1', 'UC' . str_repeat('a', 22), 'Fixture', 'unused', 'scope', 'generation'],
        );
        $lock = new Lock($this->dir);
        $google = new Google($cfg, $db, new Vault($cfg->get('APP_KEY')), new Client(), $lock);
        $api = new class ($responses) extends Api {
            public array $calls = [];

            public function __construct(private array $responses) {}

            public function call(
                string $user,
                string $method,
                array $params = [],
                ?array $body = null,
                ?string $operation = null,
                ?string $token = null,
                int $ttl = 0,
            ): array {
                $this->calls[] = [$user, $method, $params, $body];
                $response = array_shift($this->responses);
                if ($response instanceof \Throwable) {
                    throw $response;
                }
                if ($response === null) {
                    throw new \LogicException('Unexpected ' . $method);
                }

                return $response;
            }
        };

        return [new Service($google, $api, new Receipts($db, $lock), $lock), $api];
    }
}

uses(ExtendedYouTubeFixtures::class);
beforeEach(function (): void {
    $this->dir = $this->tempDir();
});
afterEach(function (): void {
    gc_collect_cycles();
    $this->removeDir($this->dir);
});

test('new reads enforce scopes and validate inputs before using the account', function (): void {
    [$service, $api] = $this->extended([]);
    foreach (
        [
            [[], 'get_video_details', ['video_ids' => ['aaaaaaaaaaa']], 'INSUFFICIENT_SERVICE_SCOPE'],
            [['videos.read'], 'get_video_details', ['video_ids' => ['invalid']], 'INVALID_ARGUMENT'],
            [
                ['channels.read'],
                'get_channel',
                ['handle' => '@fixture', 'channel_id' => 'UC' . str_repeat('a', 22)],
                'INVALID_ARGUMENT',
            ],
        ] as [$scopes, $tool, $args, $code]
    ) {
        try {
            $service->execute('u1', $scopes, $tool, $args);
            $this->fail('Invalid request accepted');
        } catch (ServiceError $error) {
            $this->assertSame($code, $error->reason);
        }
    }

    $this->assertSame([], $api->calls);
});

test('video details report omitted ids and channel uploads follow the returned uploads playlist', function (): void {
    [$service, $api] = $this->extended([
        ['items' => [['id' => 'aaaaaaaaaaa', 'contentDetails' => ['duration' => 'PT3M']]]],
        ['items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'UUfixture']]]]],
        ['items' => [], 'nextPageToken' => 'next'],
        ['items' => [['id' => 'UC' . str_repeat('b', 22)]]],
    ]);
    $details = $service->execute(
        'u1',
        ['videos.read'],
        'get_video_details',
        ['video_ids' => ['aaaaaaaaaaa', 'bbbbbbbbbbb']],
    );
    $this->assertSame(['bbbbbbbbbbb'], $details['not_returned_video_ids']);
    $uploads = $service->execute(
        'u1',
        ['channels.read'],
        'list_channel_uploads',
        ['page_size' => 10, 'page_token' => 'page'],
    );
    $this->assertSame('next', $uploads['nextPageToken']);
    $this->assertSame('UUfixture', $api->calls[2][2]['playlistId']);
    $this->assertSame('page', $api->calls[2][2]['pageToken']);
    $service->execute('u1', ['channels.read'], 'get_channel', ['handle' => '@something']);
    $this->assertSame('@something', $api->calls[3][2]['forHandle']);
});

test('search types and date boundaries stay distinct', function (): void {
    [$service, $api] = $this->extended([['items' => []], ['items' => []]]);
    $service->execute(
        'u1',
        ['channels.read'],
        'search_channels',
        ['query' => 'artist', 'published_after' => '2020-01-01T00:00:00Z'],
    );
    $service->execute('u1', ['videos.read'], 'search_playlists', ['query' => 'music', 'order' => 'date']);
    $this->assertSame('channel', $api->calls[0][2]['type']);
    $this->assertSame('playlist', $api->calls[1][2]['type']);
    try {
        $service->execute(
            'u1',
            ['videos.read'],
            'search_playlists',
            ['query' => 'x', 'published_after' => '2022-01-01T00:00:00Z', 'published_before' => '2020-01-01T00:00:00Z'],
        );
        $this->fail();
    } catch (ServiceError $error) {
        $this->assertSame('INVALID_ARGUMENT', $error->reason);
    }

    $this->assertCount(2, $api->calls);
});

test('comment writes reject another author and moderation rejects foreign video ownership', function (): void {
    [$service, $api] = $this->extended([
        ['items' => [['id' => 'comment', 'snippet' => ['authorChannelId' => ['value' => 'foreign']]]]],
        ['items' => [['id' => 'comment', 'snippet' => ['videoId' => 'aaaaaaaaaaa']]]],
        ['items' => [['id' => 'aaaaaaaaaaa', 'snippet' => ['channelId' => 'foreign']]]],
    ]);
    $result = $service->execute(
        'u1',
        ['comments.write'],
        'delete_comment',
        ['comment_id' => 'comment', 'operation_key' => 'delete-comment-test'],
    );
    $this->assertSame('NOT_COMMENT_AUTHOR', $result['error']['code']);
    $result = $service->execute(
        'u1',
        ['comments.moderate'],
        'moderate_comments',
        ['comment_ids' => ['comment'], 'status' => 'rejected', 'operation_key' => 'moderate-comment-test'],
    );
    $this->assertSame('NOT_VIDEO_OWNER', $result['error']['code']);
    $this->assertSame(['comments.list', 'comments.list', 'videos.list'], array_column($api->calls, 1));
});

test('completed comment writes replay locally and uncertain writes never automatically resume', function (): void {
    [$service, $api] = $this->extended([['id' => 'comment'], new ServiceError('UPSTREAM_UNCERTAIN', 'timeout')]);
    $args = ['video_id' => 'aaaaaaaaaaa', 'text' => 'hello', 'operation_key' => 'post-comment-test'];
    $first = $service->execute('u1', ['comments.write'], 'post_comment', $args);
    $this->assertSame('completed', $first['status']);
    $this->assertSame($first, $service->execute('u1', ['comments.write'], 'post_comment', $args));
    $args['operation_key'] = 'uncertain-comment-test';
    $result = $service->execute('u1', ['comments.write'], 'post_comment', $args);
    $this->assertSame('uncertain', $result['status']);
    try {
        $service->execute('u1', ['comments.write'], 'post_comment', $args + ['resume' => true]);
        $this->fail();
    } catch (ServiceError $error) {
        $this->assertSame('RECONCILIATION_REQUIRED', $error->reason);
    }

    $this->assertCount(2, $api->calls);
});

test('moderation verifies every selected comments video before dispatch', function (bool $foreignVideo): void {
    $channel = 'UC' . str_repeat('a', 22);
    [$service, $api] = $this->extended([
        [
            'items' => [
                ['id' => 'first', 'snippet' => ['videoId' => 'aaaaaaaaaaa']],
                ['id' => 'second', 'snippet' => ['videoId' => 'bbbbbbbbbbb']],
            ],
        ],
        [
            'items' => [
                ['id' => 'aaaaaaaaaaa', 'snippet' => ['channelId' => $channel]],
                ['id' => 'bbbbbbbbbbb', 'snippet' => ['channelId' => $foreignVideo ? 'foreign' : $channel]],
            ],
        ],
        [],
    ]);

    $receipt = $service->execute('u1', ['comments.moderate'], 'moderate_comments', [
        'comment_ids' => ['first', 'second'],
        'status' => 'heldForReview',
        'operation_key' => 'moderate-multiple-videos',
    ]);

    $this->assertSame('aaaaaaaaaaa,bbbbbbbbbbb', $api->calls[1][2]['id']);
    if ($foreignVideo) {
        $this->assertSame('partial', $receipt['status']);
        $this->assertSame('NOT_VIDEO_OWNER', $receipt['error']['code']);
        $this->assertSame(['comments.list', 'videos.list'], array_column($api->calls, 1));
        $this->assertSame([], $receipt['completed']);

        return;
    }

    $this->assertSame('completed', $receipt['status']);
    $this->assertSame(['comments.list', 'videos.list', 'comments.setModerationStatus'], array_column($api->calls, 1));
    $this->assertSame(['id' => 'first,second', 'moderationStatus' => 'heldForReview'], $api->calls[2][2]);
})->with(['all owned' => false, 'second video foreign' => true]);
