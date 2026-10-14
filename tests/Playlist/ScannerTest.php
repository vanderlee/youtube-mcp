<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Playlist;

use YouTubeMcp\Playlist\Scanner;
use YouTubeMcp\ServiceError;
use YouTubeMcp\YouTube\Api;

covers(Scanner::class);

test('scanner follows pages and preserves occurrence identifiers and positions', function (): void {
    $api = $this->createMock(Api::class);
    $calls = [];
    $api->expects($this->exactly(2))->method('call')->willReturnCallback(
        function (string $user, string $method, array $params) use (&$calls): array {
            $calls[] = [$user, $method, $params];
            if (count($calls) === 1) {
                return [
                    'items' => [['id' => 'first', 'contentDetails' => ['videoId' => 'aaaaaaaaaaa']]],
                    'nextPageToken' => 'next',
                ];
            }
            return ['items' => [['id' => 'second', 'snippet' => ['resourceId' => ['videoId' => 'aaaaaaaaaaa']]]]];
        }
    );
    $entries = (new Scanner($api))->entries('u1', 'playlist-id', microtime(true) + 30);

    expect(array_column($entries, 'id'))->toBe(['first', 'second']);
    expect(array_column($entries, 'video_id'))->toBe(['aaaaaaaaaaa', 'aaaaaaaaaaa']);
    expect(array_column($entries, 'position'))->toBe([0, 1]);
    expect($calls[0][0])->toBe('u1');
    expect($calls[0][1])->toBe('playlistItems.list');
    expect($calls[0][2]['playlistId'])->toBe('playlist-id');
    expect($calls[1][2]['pageToken'])->toBe('next');
});

test('scanner rejects invalid playlist metadata', function (array $response, string $message): void {
    $api = $this->createMock(Api::class);
    $api->expects($this->once())->method('call')->willReturn($response);
    expect(fn () => (new Scanner($api))->owned('u1', 'playlist-id', 'channel-id'))
        ->toThrow(ServiceError::class, $message);
})->with([
    'missing' => [['items' => []], 'Playlist not accessible.'],
    'mismatched' => [['items' => [['id' => 'other']]], 'Google returned a different playlist.'],
    'foreign' => [
        ['items' => [['id' => 'playlist-id', 'snippet' => ['channelId' => 'foreign']]]],
        'Only playlists owned',
    ],
]);

test('scanner returns confirmed owned metadata', function (): void {
    $metadata = ['id' => 'playlist-id', 'snippet' => ['channelId' => 'channel-id']];
    $api = $this->createMock(Api::class);
    $api->expects($this->once())->method('call')->willReturn(['items' => [$metadata]]);
    expect((new Scanner($api))->owned('u1', 'playlist-id', 'channel-id'))->toBe($metadata);
});

test('scanner refuses duplicated or missing occurrence ids', function (array $items): void {
    $api = $this->createMock(Api::class);
    $api->expects($this->once())->method('call')->willReturn(['items' => $items]);
    expect(fn () => (new Scanner($api))->entries('u1', 'playlist-id', microtime(true) + 30))
        ->toThrow(ServiceError::class, 'Playlist changed during scanning.');
})->with([
    'missing occurrence' => [[['contentDetails' => ['videoId' => 'aaaaaaaaaaa']]]],
    'duplicate occurrence' => [[['id' => 'same'], ['id' => 'same']]],
]);

test('expired scan deadlines prevent any upstream request', function (): void {
    $api = $this->createMock(Api::class);
    $api->expects($this->never())->method('call');
    expect(fn () => (new Scanner($api))->entries('u1', 'playlist-id', microtime(true) - 1))
        ->toThrow(ServiceError::class, 'time bound');
});

test('scanner fails instead of silently truncating a continuing library', function (): void {
    $api = $this->createMock(Api::class);
    $page = 0;
    $api->expects($this->exactly(20))->method('call')->willReturnCallback(function () use (&$page): array {
        return ['items' => [['id' => 'occurrence-' . ++$page]], 'nextPageToken' => 'next-' . $page];
    });
    expect(fn () => (new Scanner($api))->entries('u1', 'playlist-id', microtime(true) + 30))
        ->toThrow(ServiceError::class, 'complete scan is limited');
});
