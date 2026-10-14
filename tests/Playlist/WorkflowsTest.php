<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Playlist;

use YouTubeMcp\Auth\{Google, Vault};
use YouTubeMcp\Http\Client;
use YouTubeMcp\Operations\Receipts;
use YouTubeMcp\Playlist\Workflows;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Lock;
use YouTubeMcp\YouTube\Api;

covers(\YouTubeMcp\Playlist\Workflows::class);

trait WorkflowFixtures
{
    private string $dir;

    private function workflows(array $playlists): array
    {
        $db = $this->database();
        $this->user($db);
        $this->user($db, 'u2');
        foreach (['u1', 'u2'] as $user) {
            $db->execute(
                'INSERT INTO google_connections VALUES (?,?,?,?,?,?)',
                [$user, 'channel-' . $user, 'Fixture', 'unused', 'scope', 'generation-' . $user]
            );
        }

        $cfg = $this->config();
        $locks = new Lock($this->dir);
        $google = new Google($cfg, $db, new Vault($cfg->get('APP_KEY')), new Client(), $locks);
        $api = new class ($playlists) extends Api {
            public array $calls = [];
            public int $writes = 0;
            public ?int $failAt = null;
            public bool $uncertain = false;
            private int $nextId = 0;

            public function __construct(public array $playlists)
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
                $this->calls[] = [$user, $method, $params, $body];
                if ($method === 'playlists.list') {
                    $playlist = $this->playlists[$params['id']] ?? null;

                    return [
                        'items' => $playlist ? [
                            [
                                'id' => $params['id'],
                                'snippet' => ['channelId' => $playlist['owner']],
                                'contentDetails' => ['itemCount' => count($playlist['items'])]
                            ]
                        ] : []
                    ];
                }
                if ($method === 'playlistItems.list') {
                    $items = $this->playlists[$params['playlistId']]['items'] ?? [];
                    foreach ($items as $position => &$item) {
                        $item['snippet']['position'] = $position;
                    }
                    unset($item);
                    $offset = (int)($params['pageToken'] ?? 0);
                    $size = $params['maxResults'];

                    return ['items' => array_slice($items, $offset, $size)] + ($offset + $size < count(
                            $items
                        ) ? ['nextPageToken' => (string)($offset + $size)] : []);
                }
                if ($method === 'videos.list') {
                    return [
                        'items' => array_map(
                            fn($id) => [
                                'id' => $id,
                                'status' => ['privacyStatus' => 'private'],
                                'contentDetails' => ['regionRestriction' => ['blocked' => ['NL']]]
                            ],
                            array_filter(explode(',', $params['id']), fn($id) => $id !== 'bbbbbbbbbbb')
                        )
                    ];
                }

                $this->writes++;
                if ($this->failAt === $this->writes) {
                    throw new ServiceError(
                        $this->uncertain ? 'UPSTREAM_UNCERTAIN' : 'YOUTUBE_REQUEST_REJECTED',
                        'fixture failure'
                    );
                }
                if ($method === 'playlists.insert') {
                    $this->playlists['PLcopy0000000'] = [
                        'owner' => 'channel-' . $user,
                        'items' => [],
                        'privacy' => $body['status']['privacyStatus']
                    ];

                    return ['id' => 'PLcopy0000000'];
                }
                if ($method === 'playlistItems.insert') {
                    $id = 'new-' . ++$this->nextId;
                    $this->playlists[$body['snippet']['playlistId']]['items'][] = [
                        'id' => $id,
                        'snippet' => ['resourceId' => $body['snippet']['resourceId'], 'title' => 'added']
                    ];

                    return ['id' => $id];
                }

                foreach ($this->playlists as &$playlist) {
                    foreach ($playlist['items'] as $index => $item) {
                        if ($item['id'] !== ($params['id'] ?? $body['id'] ?? '')) {
                            continue;
                        }
                        array_splice($playlist['items'], $index, 1);
                        if ($method === 'playlistItems.update') {
                            array_splice($playlist['items'], $body['snippet']['position'], 0, [$item]);

                            return ['id' => $item['id']];
                        }

                        return [];
                    }
                }
                throw new \LogicException('Unexpected ' . $method);
            }
        };

        return [new Workflows($google, $api, new Receipts($db, $locks), $locks), $api, $db];
    }

    private function occurrence(string $id, string $video, string $title = 'Title'): array
    {
        return [
            'id' => $id,
            'snippet' => [
                'resourceId' => ['kind' => 'youtube#video', 'videoId' => $video],
                'title' => $title,
                'publishedAt' => '2020-01-01T00:00:00Z'
            ]
        ];
    }
}

uses(WorkflowFixtures::class);
beforeEach(function (): void {
    $this->dir = $this->tempDir();
});
afterEach(function (): void {
    gc_collect_cycles();
    $this->removeDir($this->dir);
});

test(
    'copy defaults private and bounded writes require explicit resume without duplicating creation or entries',
    function (): void {
        $items = [];
        for ($n = 0; $n < 24; $n++) {
            $items[] = $this->occurrence('source-' . $n, str_pad((string)$n, 11, 'a'));
        }

        $items[] = $items[0];
        $items[24]['id'] = 'extra-duplicate';
        [$service, $api] = $this->workflows(['PLsource00000' => ['owner' => 'public-channel', 'items' => $items]]);
        $args = ['source_playlist_id' => 'PLsource00000', 'title' => 'Copy', 'operation_key' => 'copy-workflow-test'];
        $first = $service->execute('u1', ['playlists.write'], 'copy_playlist', $args);
        $this->assertSame('partial', $first['status']);
        $this->assertSame('OPERATION_TIME_LIMIT', $first['error']['code']);
        $this->assertSame(20, $api->writes);
        $this->assertSame('private', $api->playlists['PLcopy0000000']['privacy']);
        $this->assertSame($first, $service->execute('u1', ['playlists.write'], 'copy_playlist', $args));
        $this->assertSame(20, $api->writes);
        $complete = $service->execute('u1', ['playlists.write'], 'copy_playlist', $args + ['resume' => true]);
        $this->assertSame('completed', $complete['status']);
        $this->assertSame(1, $complete['result']['skipped_entries']);
        $this->assertCount(24, $api->playlists['PLcopy0000000']['items']);
        $this->assertSame(25, $api->writes);
        $calls = count($api->calls);
        $service->execute('u1', ['playlists.write'], 'copy_playlist', $args);
        $this->assertCount($calls, $api->calls);
    }
);

test('sort and duplicate removal preserve the correct occurrences', function (): void {
    [$service, $api] = $this->workflows([
        'PLtarget00000' => [
            'owner' => 'channel-u1',
            'items' => [
                $this->occurrence('z', 'aaaaaaaaaaa', 'Z'),
                $this->occurrence('a', 'bbbbbbbbbbb', 'A'),
                $this->occurrence('b', 'aaaaaaaaaaa', 'B')
            ]
        ]
    ]);
    $sort = $service->execute(
        'u1',
        ['playlists.write'],
        'sort_playlist',
        ['playlist_id' => 'PLtarget00000', 'order' => 'title_asc', 'operation_key' => 'sort-workflow-test']
    );
    $this->assertSame('completed', $sort['status']);
    $this->assertSame(['a', 'b', 'z'], array_column($api->playlists['PLtarget00000']['items'], 'id'));
    $remove = $service->execute(
        'u1',
        ['playlists.write'],
        'remove_playlist_duplicates',
        ['playlist_id' => 'PLtarget00000', 'keep' => 'last', 'operation_key' => 'dedupe-workflow-test']
    );
    $this->assertSame('completed', $remove['status']);
    $this->assertSame(['a', 'z'], array_column($api->playlists['PLtarget00000']['items'], 'id'));
});

test(
    'workflow scopes and destination ownership fail before writes and scans never silently truncate',
    function (): void {
        $items = array_map(fn($n) => $this->occurrence('entry-' . $n, 'aaaaaaaaaaa'), range(0, 1000));
        [$service, $api] = $this->workflows(['PLtarget00000' => ['owner' => 'channel-u1', 'items' => $items]]);
        try {
            $service->execute(
                'u1',
                [],
                'sort_playlist',
                ['playlist_id' => 'PLtarget00000', 'order' => 'title_asc', 'operation_key' => 'scope-workflow-test']
            );
            $this->fail();
        } catch (ServiceError $error) {
            $this->assertSame('INSUFFICIENT_SERVICE_SCOPE', $error->reason);
        }

        $this->assertSame([], $api->calls);
        $result = $service->execute(
            'u2',
            ['playlists.write'],
            'sort_playlist',
            ['playlist_id' => 'PLtarget00000', 'order' => 'title_asc', 'operation_key' => 'owner-workflow-test']
        );
        $this->assertSame('NOT_PLAYLIST_OWNER', $result['error']['code']);
        $result = $service->execute(
            'u1',
            ['playlists.write'],
            'sort_playlist',
            ['playlist_id' => 'PLtarget00000', 'order' => 'title_asc', 'operation_key' => 'bound-workflow-test']
        );
        $this->assertSame('PLAYLIST_SCAN_LIMIT', $result['error']['code']);
        $this->assertSame(0, $api->writes);
    }
);

test('a saved workflow detects external edits and uncertain changes cannot resume', function (): void {
    [$service, $api] = $this->workflows(
        [
            'PLsource00000' => [
                'owner' => 'public',
                'items' => [$this->occurrence('one', 'aaaaaaaaaaa'), $this->occurrence('two', 'bbbbbbbbbbb')]
            ]
        ]
    );
    $api->failAt = 3;
    $args = ['source_playlist_id' => 'PLsource00000', 'title' => 'Copy', 'operation_key' => 'changed-workflow-test'];
    $first = $service->execute('u1', ['playlists.write'], 'copy_playlist', $args);
    $this->assertSame('partial', $first['status']);
    $api->playlists['PLcopy0000000']['items'][] = $this->occurrence('external', 'ccccccccccc');
    $resume = $service->execute('u1', ['playlists.write'], 'copy_playlist', $args + ['resume' => true]);
    $this->assertSame('PLAYLIST_CHANGED', $resume['error']['code']);
    $this->assertSame(3, $api->writes);
    [$service, $api] = $this->workflows(
        ['PLsource00000' => ['owner' => 'public', 'items' => [$this->occurrence('one', 'aaaaaaaaaaa')]]]
    );
    $api->failAt = 2;
    $api->uncertain = true;
    $args['operation_key'] = 'uncertain-workflow-test';
    $first = $service->execute('u1', ['playlists.write'], 'copy_playlist', $args);
    $this->assertSame('uncertain', $first['status']);
    try {
        $service->execute('u1', ['playlists.write'], 'copy_playlist', $args + ['resume' => true]);
        $this->fail();
    } catch (ServiceError $error) {
        $this->assertSame('RECONCILIATION_REQUIRED', $error->reason);
    }

    $this->assertSame(2, $api->writes);
});

test('transfer moves add before removing source occurrences and reject a foreign source', function (): void {
    [$service, $api] = $this->workflows([
        'PLsource00000' => ['owner' => 'channel-u1', 'items' => [$this->occurrence('source', 'aaaaaaaaaaa')]],
        'PLtarget00000' => ['owner' => 'channel-u1', 'items' => []]
    ]);
    $args = [
        'source_playlist_id' => 'PLsource00000',
        'playlist_id' => 'PLtarget00000',
        'playlist_item_ids' => ['source'],
        'move' => true,
        'operation_key' => 'transfer-workflow-test'
    ];
    $result = $service->execute('u1', ['playlists.write'], 'transfer_tracks', $args);
    $this->assertSame('completed', $result['status']);
    $this->assertCount(0, $api->playlists['PLsource00000']['items']);
    $this->assertCount(1, $api->playlists['PLtarget00000']['items']);
    $writes = array_values(
        array_filter(
            array_column($api->calls, 1),
            fn($method) => in_array($method, ['playlistItems.insert', 'playlistItems.delete'], true)
        )
    );
    $this->assertSame(['playlistItems.insert', 'playlistItems.delete'], $writes);
});

test(
    'paged exports preserve continuation escape spreadsheet formulas and audits never imply deletion',
    function (): void {
        [$service, $api] = $this->workflows(
            [
                'PLtarget00000' => [
                    'owner' => 'public',
                    'items' => [
                        $this->occurrence('one', 'aaaaaaaaaaa', '=DANGEROUS()'),
                        $this->occurrence('two', 'bbbbbbbbbbb')
                    ]
                ]
            ]
        );
        $export = $service->execute(
            'u1',
            ['playlists.read'],
            'export_playlist',
            ['playlist_id' => 'PLtarget00000', 'page_size' => 1, 'format' => 'csv']
        );
        $this->assertFalse($export['complete']);
        $this->assertSame('1', $export['next_page_token']);
        $this->assertStringContainsString("'=DANGEROUS()", $export['csv']);
        $audit = $service->execute(
            'u1',
            ['playlists.read'],
            'audit_playlist',
            ['playlist_id' => 'PLtarget00000', 'region' => 'NL']
        );
        $this->assertTrue($audit['entries'][0]['region_restricted']);
        $this->assertFalse($audit['entries'][1]['returned']);
        $this->assertNull($audit['entries'][1]['region_restricted']);
        $this->assertStringContainsString('does not distinguish deletion', $audit['note']);
        $this->assertSame(0, $api->writes);
    }
);

test('merging skips duplicate video ids and comparison retains occurrence counts', function (): void {
    [$service, $api] = $this->workflows([
        'PLtarget00000' => ['owner' => 'channel-u1', 'items' => [$this->occurrence('target', 'aaaaaaaaaaa')]],
        'PLsource00000' => [
            'owner' => 'public',
            'items' => [
                $this->occurrence('s1', 'aaaaaaaaaaa'),
                $this->occurrence('s2', 'bbbbbbbbbbb'),
                $this->occurrence('s3', 'bbbbbbbbbbb')
            ]
        ],
        'PLsource00001' => ['owner' => 'public', 'items' => [$this->occurrence('s4', 'ccccccccccc')]],
    ]);
    $result = $service->execute(
        'u1',
        ['playlists.write'],
        'merge_playlists',
        [
            'playlist_id' => 'PLtarget00000',
            'source_playlist_ids' => ['PLsource00000', 'PLsource00001'],
            'operation_key' => 'merge-workflow-test'
        ]
    );
    $this->assertSame('completed', $result['status']);
    $this->assertSame(2, $result['result']['skipped_entries']);
    $this->assertSame(
        ['aaaaaaaaaaa', 'bbbbbbbbbbb', 'ccccccccccc'],
        array_column(
            array_column(array_column($api->playlists['PLtarget00000']['items'], 'snippet'), 'resourceId'),
            'videoId'
        ),
    );
    $compare = $service->execute(
        'u1',
        ['playlists.read'],
        'compare_playlists',
        ['playlist_id' => 'PLsource00000', 'other_playlist_id' => 'PLsource00001']
    );
    $this->assertSame(2, $compare['left_occurrence_counts']['bbbbbbbbbbb']);
    $this->assertSame(['ccccccccccc'], $compare['only_in_right']);
    $this->assertTrue($compare['complete']);
});

test(
    'reorder validates an exact permutation and added-date sorting compares timestamps rather than formatted text',
    function (): void {
        $first = $this->occurrence('first', 'aaaaaaaaaaa');
        $first['snippet']['publishedAt'] = '2020-01-01T00:00:00Z';
        $second = $this->occurrence('second', 'bbbbbbbbbbb');
        $second['snippet']['publishedAt'] = '2020-01-01T00:00:00.001Z';
        [$service, $api] = $this->workflows(['PLtarget00000' => ['owner' => 'channel-u1', 'items' => [$second, $first]]]
        );
        $args = [
            'playlist_id' => 'PLtarget00000',
            'playlist_item_ids' => ['first'],
            'operation_key' => 'invalid-reorder-test'
        ];
        $result = $service->execute('u1', ['playlists.write'], 'reorder_playlist', $args);
        $this->assertSame('INVALID_ARGUMENT', $result['error']['code']);
        $this->assertSame(0, $api->writes);
        $result = $service->execute(
            'u1',
            ['playlists.write'],
            'sort_playlist',
            ['playlist_id' => 'PLtarget00000', 'order' => 'added_oldest', 'operation_key' => 'added-date-sort-test']
        );
        $this->assertSame('completed', $result['status']);
        $this->assertSame(['first', 'second'], array_column($api->playlists['PLtarget00000']['items'], 'id'));
        $result = $service->execute(
            'u1',
            ['playlists.write'],
            'reorder_playlist',
            [
                'playlist_id' => 'PLtarget00000',
                'playlist_item_ids' => ['second', 'first'],
                'operation_key' => 'valid-reorder-test'
            ]
        );
        $this->assertSame('completed', $result['status']);
        $this->assertSame(['second', 'first'], array_column($api->playlists['PLtarget00000']['items'], 'id'));
    }
);

test(
    'partial transfer verifies both snapshots and never repeats a completed addition',
    function (bool $sourceChanged): void {
        [$service, $api] = $this->workflows([
            'PLsource00000' => ['owner' => 'channel-u1', 'items' => [$this->occurrence('source', 'aaaaaaaaaaa')]],
            'PLtarget00000' => ['owner' => 'channel-u1', 'items' => []],
        ]);
        $arguments = [
            'source_playlist_id' => 'PLsource00000',
            'playlist_id' => 'PLtarget00000',
            'playlist_item_ids' => ['source'],
            'move' => true,
            'operation_key' => 'resume-transfer-snapshots',
        ];
        $api->failAt = 2;

        $first = $service->execute('u1', ['playlists.write'], 'transfer_tracks', $arguments);
        $this->assertSame('partial', $first['status']);
        $this->assertSame('YOUTUBE_REQUEST_REJECTED', $first['error']['code']);
        $this->assertCount(1, $api->playlists['PLsource00000']['items']);
        $this->assertCount(1, $api->playlists['PLtarget00000']['items']);
        if ($sourceChanged) {
            $api->playlists['PLsource00000']['items'][] = $this->occurrence('external', 'bbbbbbbbbbb');
        }

        $resumed = $service->execute('u1', ['playlists.write'], 'transfer_tracks', $arguments + ['resume' => true]);
        $this->assertCount(1, $api->playlists['PLtarget00000']['items']);
        $this->assertSame(1, count(array_filter($api->calls, fn($call) => $call[1] === 'playlistItems.insert')));
        if ($sourceChanged) {
            $this->assertSame('PLAYLIST_CHANGED', $resumed['error']['code']);
            $this->assertSame(2, $api->writes);
            $this->assertCount(2, $api->playlists['PLsource00000']['items']);

            return;
        }

        $this->assertSame('completed', $resumed['status']);
        $this->assertSame(3, $api->writes);
        $this->assertSame([], $api->playlists['PLsource00000']['items']);
    }
)->with(['unchanged source' => false, 'externally changed source' => true]);
