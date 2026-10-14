<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests;

use YouTubeMcp\Mcp\Presentation;

test('compact listing retains ids and pagination with much smaller payload', function (): void {
    $raw = [
        'items' => [
            [
                'id' => 'PLtest123456',
                'snippet' => [
                    'title' => 'Playlist',
                    'description' => str_repeat('long description ', 1000),
                    'thumbnails' => ['default' => ['url' => 'https://example.test/image']]
                ],
                'status' => ['privacyStatus' => 'private'],
                'contentDetails' => ['itemCount' => 12]
            ]
        ],
        'nextPageToken' => 'next'
    ];
    $r = Presentation::compact('list_playlists', $raw);
    $this->assertSame('PLtest123456', $r['items'][0]['playlist_id']);
    $this->assertSame('next', $r['next_page_token']);
    $this->assertLessThan(strlen(json_encode($raw)) / 10, strlen(json_encode($r)));
    $this->assertArrayHasKey('description', $raw['items'][0]['snippet']);
});

test('receipt summary preserves recovery and occurrence ids without raw snippets', function (): void {
    $raw = [
        'operation_id' => 'op1',
        'status' => 'uncertain',
        'tool' => 'add_tracks',
        'pending_step' => 'add:1',
        'error' => ['code' => 'UPSTREAM_UNCERTAIN'],
        'completed' => [
            'add:0' => [
                'id' => 'occurrence1',
                'snippet' => [
                    'resourceId' => ['videoId' => 'aaaaaaaaaaa'],
                    'description' => str_repeat('long ', 1000)
                ]
            ]
        ]
    ];
    $r = Presentation::compact('get_operation', $raw);
    $this->assertSame('occurrence1', $r['entries'][0]['id']);
    $this->assertSame('add:1', $r['pending_step']);
    $this->assertSame($raw['error'], $r['error']);
    $this->assertArrayNotHasKey('completed', $r);
});
