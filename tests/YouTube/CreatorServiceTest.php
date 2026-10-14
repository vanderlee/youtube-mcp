<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\YouTube;

use Nyholm\Psr7\{Stream, UploadedFile};
use YouTubeMcp\Auth\{Google, Vault};
use YouTubeMcp\Http\{Client, MediaClient};
use YouTubeMcp\Operations\Receipts;
use YouTubeMcp\Quota\Budget;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Lock;
use YouTubeMcp\YouTube\{Api, CreatorService, MediaApi, MediaStore};

covers(\YouTubeMcp\YouTube\CreatorService::class);
covers(\YouTubeMcp\YouTube\MediaStore::class);
covers(\YouTubeMcp\YouTube\MediaApi::class);

trait CreatorFixtures
{
    private string $dir;

    private function creators(array $apiResponses = [], array $mediaResponses = []): array
    {
        $db = $this->database();
        $cfg = $this->config(['STORAGE_PATH' => $this->dir]);
        $vault = new Vault($cfg->get('APP_KEY'));
        $locks = new Lock($this->dir . '/locks');
        foreach (['u1', 'u2'] as $user) {
            $this->user($db, $user);
            $db->execute('INSERT INTO google_connections VALUES (?,?,?,?,?,?)', [
                $user,
                'channel-' . $user,
                'Fixture',
                $vault->encrypt(['access_token' => 'dummy-access', 'expires_at' => time() + 3600]),
                Google::YOUTUBE_SCOPE,
                'generation-' . $user
            ]);
        }

        $google = new Google($cfg, $db, $vault, new Client(), $locks);
        $api = new class ($apiResponses) extends Api {
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
                $result = array_shift($this->responses);
                if ($result === null) {
                    throw new \LogicException('Unexpected ' . $method);
                }

                return $result;
            }
        };
        $http = new class ($mediaResponses) extends MediaClient {
            public array $calls = [];

            public function __construct(private array $responses)
            {
            }

            public function exchange(
                string $method,
                string $url,
                array $headers,
                ?string $body = null,
                ?string $path = null
            ): array {
                $this->calls[] = [$method, $url, $headers, $body, $path];
                $result = array_shift($this->responses);
                if ($result instanceof \Throwable) {
                    throw $result;
                }
                if ($result === null) {
                    throw new \LogicException('Unexpected media request');
                }

                return $result;
            }
        };
        $assets = new MediaStore($db, $cfg);
        $media = new MediaApi($google, new Budget($db, $cfg), $vault, $http);
        $receipts = new Receipts($db, $locks);

        return [
            new CreatorService($google, $api, $receipts, $locks, $assets, $media),
            $assets,
            $api,
            $http,
            $db,
            $google,
            $media,
            $receipts,
            $locks
        ];
    }

    private function captionUpload(): UploadedFile
    {
        $text = "1\n00:00:01,000 --> 00:00:02,000\nPrivate fixture caption\n";

        return new UploadedFile(
            Stream::create($text),
            strlen($text),
            UPLOAD_ERR_OK,
            '../../unsafe-name.srt',
            'text/plain'
        );
    }
}

uses(CreatorFixtures::class);
beforeEach(function (): void {
    $this->dir = $this->tempDir();
});
afterEach(function (): void {
    gc_collect_cycles();
    $this->removeDir($this->dir);
});

test(
    'staged media ignores filenames and is isolated by user channel generation expiry and content hash',
    function (): void {
        [, $assets, , , $db, $google] = $this->creators();
        $connection = $google->connection('u1');
        $saved = $assets->save('u1', $connection, $this->captionUpload());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $saved['asset_id']);
        $file = $assets->get('u1', $connection, $saved['asset_id']);
        $this->assertFileExists($this->dir . '/media/' . $saved['asset_id']);
        $this->assertSame('text/plain', $file['mime']);
        foreach (
            [
                ['u2', $google->connection('u2')],
                ['u1', array_replace($connection, ['generation' => 'new'])],
                ['u1', array_replace($connection, ['channel_id' => 'foreign'])]
            ] as [$user, $binding]
        ) {
            try {
                $assets->get($user, $binding, $saved['asset_id']);
                $this->fail('Cross account media accepted');
            } catch (ServiceError $error) {
                $this->assertSame('MEDIA_NOT_FOUND', $error->reason);
            }
        }
        file_put_contents($file['path'], 'changed');
        try {
            $assets->get('u1', $connection, $saved['asset_id']);
            $this->fail();
        } catch (ServiceError $error) {
            $this->assertSame('MEDIA_NOT_FOUND', $error->reason);
        }

        $db->execute('UPDATE media_assets SET created_at=0 WHERE id=?', [$saved['asset_id']]);
        $assets->cleanup(new Lock($this->dir . '/locks'));
        $this->assertFileDoesNotExist($file['path']);
        $this->assertSame([], $db->all('SELECT id FROM media_assets'));
    }
);

test('creator scopes and ownership checks stop writes before dispatch', function (): void {
    [$service, , $api, $http] = $this->creators(
        [['items' => [['id' => 'aaaaaaaaaaa', 'snippet' => ['channelId' => 'foreign']]]]]
    );
    try {
        $service->execute(
            'u1',
            ['playlists.write'],
            'delete_video',
            ['video_id' => 'aaaaaaaaaaa', 'operation_key' => 'creator-owner-test']
        );
        $this->fail();
    } catch (ServiceError $error) {
        $this->assertSame('INSUFFICIENT_SERVICE_SCOPE', $error->reason);
    }

    $this->assertSame([], $api->calls);
    $result = $service->execute(
        'u1',
        ['videos.write'],
        'delete_video',
        ['video_id' => 'aaaaaaaaaaa', 'operation_key' => 'creator-owner-test']
    );
    $this->assertSame('NOT_VIDEO_OWNER', $result['error']['code']);
    $this->assertCount(1, $api->calls);
    $this->assertSame([], $http->calls);
});

test(
    'video metadata changes preserve unspecified snippet and privacy properties and replay locally',
    function (): void {
        [$service, , $api] = $this->creators([
            [
                'items' => [
                    [
                        'id' => 'aaaaaaaaaaa',
                        'snippet' => [
                            'channelId' => 'channel-u1',
                            'title' => 'Keep',
                            'description' => 'Old',
                            'categoryId' => '10',
                            'tags' => ['keep-tag']
                        ],
                        'status' => ['privacyStatus' => 'private']
                    ]
                ]
            ],
            ['id' => 'aaaaaaaaaaa'],
        ]);
        $args = ['video_id' => 'aaaaaaaaaaa', 'description' => 'New', 'operation_key' => 'creator-metadata-test'];
        $result = $service->execute('u1', ['videos.write'], 'update_video', $args);
        $this->assertSame('completed', $result['status']);
        $body = $api->calls[1][2];
        $this->assertSame('Keep', $body['snippet']['title']);
        $this->assertSame(['keep-tag'], $body['snippet']['tags']);
        $this->assertSame('New', $body['snippet']['description']);
        $this->assertArrayNotHasKey('status', $body);
        $this->assertSame($result, $service->execute('u1', ['videos.write'], 'update_video', $args));
        $this->assertCount(2, $api->calls);
    }
);

test(
    'media upload receipts encrypt session urls and interrupted content cannot automatically replay',
    function (): void {
        [, $assets, , $http, , $google, $media, $receipts] = $this->creators([], [
            [
                'status' => 200,
                'headers' => ['location' => 'https://www.googleapis.com/upload/youtube/v3/captions?upload_id=private-session'],
                'raw' => ''
            ],
            new ServiceError('UPSTREAM_UNCERTAIN', 'fixture timeout'),
        ]);
        $connection = $google->connection('u1');
        $saved = $assets->save('u1', $connection, $this->captionUpload());
        $asset = $assets->get('u1', $connection, $saved['asset_id']);
        $args = ['operation_key' => 'media-uncertain-test', 'asset_id' => $saved['asset_id']];
        $work = fn($step) => $media->upload(
            'u1',
            'captions.insert',
            ['part' => 'snippet'],
            ['snippet' => ['videoId' => 'aaaaaaaaaaa', 'language' => 'en', 'name' => '']],
            $asset,
            $step
        );
        $result = $receipts->run('u1', $connection['generation'], 'upload_caption', $args, $work);
        $this->assertSame('uncertain', $result['status']);
        $this->assertStringNotContainsString('private-session', json_encode($result));
        $this->assertStringNotContainsString('dummy-access', json_encode($result));
        $this->assertSame('PUT', $http->calls[1][0]);
        try {
            $receipts->run('u1', $connection['generation'], 'upload_caption', $args + ['resume' => true], $work);
            $this->fail();
        } catch (ServiceError $error) {
            $this->assertSame('RECONCILIATION_REQUIRED', $error->reason);
        }

        $this->assertCount(2, $http->calls);
    }
);

test('upload session urls reject offsite hosts credentials nonhttps and unexpected paths', function (): void {
    foreach (
        [
            'https://evil.test/upload/youtube/v3/videos',
            'http://www.googleapis.com/upload/youtube/v3/videos',
            'https://user@www.googleapis.com/upload/youtube/v3/videos',
            'https://www.googleapis.com/other',
            'https://www.googleapis.com:443/upload/youtube/v3/videos'
        ] as $url
    ) {
        try {
            MediaApi::validateLocation($url);
            $this->fail('Unsafe session URL accepted');
        } catch (ServiceError $error) {
            $this->assertSame('UPSTREAM_UNCERTAIN', $error->reason);
        }
    }
});

test('caption download requires an exact track binding and preserves bounded raw subtitle content', function (): void {
    [$service, , , $http] = $this->creators([
        ['items' => [['id' => 'aaaaaaaaaaa', 'snippet' => ['channelId' => 'channel-u1']]]],
        ['items' => [['id' => 'track', 'snippet' => ['videoId' => 'aaaaaaaaaaa']]]],
    ], [['status' => 200, 'headers' => [], 'raw' => "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nhello\n"]]);
    $result = $service->execute(
        'u1',
        ['captions.read'],
        'download_caption',
        ['video_id' => 'aaaaaaaaaaa', 'caption_id' => 'track']
    );
    $this->assertStringStartsWith('WEBVTT', $result['content']);
    $this->assertSame('GET', $http->calls[0][0]);
    [$service, , , $http] = $this->creators([
        ['items' => [['id' => 'aaaaaaaaaaa', 'snippet' => ['channelId' => 'channel-u1']]]],
        ['items' => [['id' => 'track', 'snippet' => ['videoId' => 'bbbbbbbbbbb']]]],
    ]);
    try {
        $service->execute(
            'u1',
            ['captions.read'],
            'download_caption',
            ['video_id' => 'aaaaaaaaaaa', 'caption_id' => 'track']
        );
        $this->fail();
    } catch (ServiceError $error) {
        $this->assertSame('CAPTION_NOT_FOUND', $error->reason);
    }

    $this->assertSame([], $http->calls);
});

test(
    'new video uploads default private and their completed receipts replay without more media requests',
    function (): void {
        [$service, $assets, $api, $http, , $google] = $this->creators([
            ['items' => [['id' => 'channel-u1']]],
        ], [
            [
                'status' => 200,
                'headers' => ['location' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=secret-session'],
                'raw' => ''
            ],
            ['status' => 200, 'headers' => [], 'raw' => '{"id":"aaaaaaaaaaa"}'],
        ]);
        $video = pack('N', 24) . 'ftypisom' . pack('N', 0) . 'isomiso2';
        $saved = $assets->save(
            'u1',
            $google->connection('u1'),
            new UploadedFile(Stream::create($video), strlen($video), UPLOAD_ERR_OK, 'fixture.mp4')
        );
        $args = ['asset_id' => $saved['asset_id'], 'title' => 'Fixture', 'operation_key' => 'video-upload-test'];
        $result = $service->execute('u1', ['videos.upload'], 'upload_video', $args);
        $this->assertSame('completed', $result['status']);
        $this->assertSame('aaaaaaaaaaa', $result['result']['video_id']);
        $metadata = json_decode($http->calls[0][3], true);
        $this->assertSame('private', $metadata['status']['privacyStatus']);
        $this->assertStringNotContainsString('secret-session', json_encode($result));
        $this->assertSame($result, $service->execute('u1', ['videos.upload'], 'upload_video', $args));
        $this->assertCount(2, $http->calls);
        $this->assertCount(1, $api->calls);
    }
);

test('channel branding preserves other fields and foreign presentation sections cannot be changed', function (): void {
    [$service, , $api] = $this->creators([
        [
            'items' => [
                [
                    'id' => 'channel-u1',
                    'brandingSettings' => [
                        'channel' => [
                            'description' => 'Old',
                            'keywords' => 'keep',
                            'country' => 'NL'
                        ]
                    ]
                ]
            ]
        ],
        ['id' => 'channel-u1'],
        ['items' => [['id' => 'section', 'snippet' => ['channelId' => 'foreign']]]],
    ]);
    $result = $service->execute(
        'u1',
        ['channels.write'],
        'update_channel_branding',
        ['description' => 'New', 'operation_key' => 'branding-update-test']
    );
    $this->assertSame('completed', $result['status']);
    $body = $api->calls[1][2];
    $this->assertSame('keep', $body['brandingSettings']['channel']['keywords']);
    $this->assertSame('NL', $body['brandingSettings']['channel']['country']);
    $this->assertSame('New', $body['brandingSettings']['channel']['description']);
    $result = $service->execute(
        'u1',
        ['channels.write'],
        'delete_channel_section',
        ['section_id' => 'section', 'operation_key' => 'foreign-section-test']
    );
    $this->assertSame('NOT_CHANNEL_OWNER', $result['error']['code']);
    $this->assertCount(3, $api->calls);
});

test('owned media purge removes only that users files and malformed captions are not staged', function (): void {
    [, $assets, , , $db, $google] = $this->creators();
    $first = $assets->save('u1', $google->connection('u1'), $this->captionUpload());
    $second = $assets->save('u2', $google->connection('u2'), $this->captionUpload());
    $assets->purge('u1');
    $this->assertFileDoesNotExist($this->dir . '/media/' . $first['asset_id']);
    $this->assertFileExists($this->dir . '/media/' . $second['asset_id']);
    $this->assertSame('u2', $db->one('SELECT user_id FROM media_assets')['user_id']);
    $bad = 'not a timed subtitle';
    try {
        $assets->save(
            'u1',
            $google->connection('u1'),
            new UploadedFile(Stream::create($bad), strlen($bad), UPLOAD_ERR_OK, 'bad.srt')
        );
        $this->fail();
    } catch (ServiceError $error) {
        $this->assertSame('INVALID_MEDIA', $error->reason);
    }

    $this->assertSame(1, (int)$db->one('SELECT COUNT(*) AS n FROM media_assets')['n']);
});

test(
    'section types enforce their reference and title constraints before writing',
    function (array $section, bool $valid): void {
        [$service, , $api, , $db] = $this->creators([['id' => 'created-section']]);
        $db->execute('UPDATE google_connections SET channel_id=? WHERE user_id=?', ['UC' . str_repeat('a', 22), 'u1']);

        $receipt = $service->execute(
            'u1',
            ['channels.write'],
            'create_channel_section',
            $section + [
                'operation_key' => 'section-constraint-test',
            ]
        );

        if (!$valid) {
            $this->assertSame('partial', $receipt['status']);
            $this->assertSame('INVALID_ARGUMENT', $receipt['error']['code']);
            $this->assertSame([], $api->calls);

            return;
        }

        $this->assertSame('completed', $receipt['status']);
        $this->assertSame('created-section', $receipt['result']['section_id']);
        $this->assertCount(1, $api->calls);
        $this->assertSame('channelSections.insert', $api->calls[0][0]);
        $this->assertSame($section['type'], $api->calls[0][2]['snippet']['type']);
    }
)->with([
    'single playlist' => [
        ['type' => 'singlePlaylist', 'playlist_ids' => ['PLsource00000']],
        true,
    ],
    'single playlist with two references' => [
        ['type' => 'singlePlaylist', 'playlist_ids' => ['PLsource00000', 'PLsource00001']],
        false,
    ],
    'multiple playlists' => [
        ['type' => 'multiplePlaylists', 'playlist_ids' => ['PLsource00000'], 'title' => 'Playlists'],
        true,
    ],
    'multiple playlists without title' => [
        ['type' => 'multiplePlaylists', 'playlist_ids' => ['PLsource00000']],
        false,
    ],
    'multiple channels' => [
        ['type' => 'multipleChannels', 'channel_ids' => ['UC' . str_repeat('b', 22)], 'title' => 'Channels'],
        true,
    ],
    'multiple channels including self' => [
        ['type' => 'multipleChannels', 'channel_ids' => ['UC' . str_repeat('a', 22)], 'title' => 'Channels'],
        false,
    ],
]);
