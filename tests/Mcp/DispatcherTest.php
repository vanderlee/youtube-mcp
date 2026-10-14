<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Mcp;

use YouTubeMcp\Auth\{Google, Vault};
use YouTubeMcp\Http\{Client, MediaClient};
use YouTubeMcp\Mcp\Dispatcher;
use YouTubeMcp\Operations\Receipts;
use YouTubeMcp\Playlist\{Service as PlaylistService, Workflows};
use YouTubeMcp\Quota\Usage;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Lock;
use YouTubeMcp\YouTube\{Api, CreatorService, MediaApi, MediaStore, Service as YouTubeService};

covers(Dispatcher::class);

test('dispatcher routes each domain through its scoped service', function (
    string $tool,
    string $scope,
    array $arguments,
    string $resource,
    array $expected,
): void {
    $directory = $this->tempDir();
    try {
        $db = $this->database();
        $this->user($db);
        $config = $this->config(['STORAGE_PATH' => $directory]);
        $vault = new Vault($config->get('APP_KEY'));
        $locks = new Lock($directory . '/locks');
        $db->execute('INSERT INTO google_connections VALUES (?,?,?,?,?,?)', [
            'u1', 'channel1', 'Fixture',
            $vault->encrypt(['access_token' => 'dummy-access', 'expires_at' => time() + 3600]),
            Google::YOUTUBE_SCOPE, 'generation1',
        ]);
        $http = new class extends Client {
            public array $paths = [];

            public function request(string $method, string $url, array $headers = [], ?string $body = null): array
            {
                $this->paths[] = parse_url($url, PHP_URL_PATH);

                return ['status' => 200, 'data' => ['items' => []]];
            }
        };
        $google = new Google($config, $db, $vault, $http, $locks);
        $usage = new Usage($db);
        $api = new Api($http, $usage, $db, $google);
        $receipts = new Receipts($db, $locks);
        $dispatcher = new Dispatcher(
            new PlaylistService($google, $api, $usage, $receipts, $locks),
            new YouTubeService($google, $api, $receipts, $locks),
            new Workflows($google, $api, $receipts, $locks),
            new CreatorService($google, $api, $receipts, $locks, new MediaStore($db, $config), new MediaApi($google, $usage, $vault, new MediaClient())),
            $google,
            $receipts,
            $locks,
        );

        expect(fn() => $dispatcher->execute('u1', [], $tool, $arguments))
            ->toThrow(ServiceError::class, 'Authorize');
        expect($http->paths)->toBe([]);
        expect($dispatcher->execute('u1', [$scope], $tool, $arguments))->toBe($expected);
        expect($http->paths)->toBe(['/youtube/v3/' . $resource]);
        expect(fn() => $dispatcher->execute('u1', [$scope], 'unknown_tool', []))
            ->toThrow(ServiceError::class, 'Unknown tool');
        expect($http->paths)->toHaveCount(1);
    } finally {
        $this->removeDir($directory);
    }
})->with([
    'playlist' => ['list_playlists', 'playlists.read', [], 'playlists', ['items' => []]],
    'youtube' => ['get_channel', 'channels.read', [], 'channels', ['items' => []]],
    'workflow' => ['export_playlist', 'playlists.read', ['playlist_id' => 'PLfixture123'], 'playlistItems', [
        'next_page_token' => null, 'complete' => true, 'format' => 'json', 'entries' => [],
    ]],
    'creator' => ['list_video_categories', 'videos.read', [], 'videoCategories', ['items' => []]],
]);
