<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\YouTube;

use YouTubeMcp\Auth\{Google, Vault};
use YouTubeMcp\Http\{Client, MediaClient};
use YouTubeMcp\Operations\Receipts;
use YouTubeMcp\Quota\Budget;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Lock;
use YouTubeMcp\YouTube\{MediaApi, ResponseCache};

covers(MediaApi::class);

test('upload phases preserve encrypted receipts and invalidate caches around content dispatch', function (
    int $contentStatus,
    string $content,
    string $expectedStatus,
): void {
    $db = $this->database();
    $this->user($db);
    $directory = $this->tempDir();
    try {
        $config = $this->config(['STORAGE_PATH' => $directory]);
        $vault = new Vault($config->get('APP_KEY'));
        $locks = new Lock($directory . '/locks');
        $db->execute('INSERT INTO google_connections VALUES (?,?,?,?,?,?)', [
            'u1',
            'channel1',
            'Fixture',
            $vault->encrypt(['access_token' => 'dummy-access', 'expires_at' => time() + 3600]),
            Google::YOUTUBE_SCOPE,
            'generation1',
        ]);
        $cache = new ResponseCache($db);
        $cachedData = ['items' => [['id' => 'aaaaaaaaaaa']]];
        $cache->put('u1', 'video-cache', $cachedData, 30, $cache->versions('u1', ['video:aaaaaaaaaaa']));
        $location = 'https://www.googleapis.com/upload/youtube/v3/thumbnails?upload_id=fixture';
        $http = new class ($cache, $cachedData, $location, $contentStatus, $content) extends MediaClient {
            public int $calls = 0;

            public function __construct(
                private ResponseCache $cache,
                private array $cachedData,
                private string $location,
                private int $status,
                private string $content,
            ) {}

            public function exchange(string $method, string $url, array $headers, ?string $body = null, ?string $path = null): array
            {
                $this->calls++;
                if ($this->calls === 1) {
                    expect($method)->toBe('POST');
                    expect($path)->toBeNull();
                    expect($this->cache->get('u1', 'video-cache'))->toBe($this->cachedData);

                    return ['status' => 200, 'headers' => ['location' => $this->location], 'raw' => ''];
                }

                expect($this->calls)->toBe(2);
                expect($method)->toBe('PUT');
                expect($url)->toBe($this->location);
                expect($path)->toBe('fixture-image');
                expect($this->cache->get('u1', 'video-cache'))->toBeNull();
                // A read finishing during the upload must also be invalidated afterward.
                $this->cache->put('u1', 'video-cache', $this->cachedData, 30, $this->cache->versions('u1', ['video:aaaaaaaaaaa']));

                return ['status' => $this->status, 'headers' => [], 'raw' => $this->content];
            }
        };
        $google = new Google($config, $db, $vault, new Client(), $locks);
        $media = new MediaApi($google, new Budget($db, $config), $vault, $http, $cache);
        $receipts = new Receipts($db, $locks);
        $arguments = ['operation_key' => 'upload-lifecycle'];
        $receipt = $receipts->run('u1', 'generation1', 'set_video_thumbnail', $arguments, fn($step) => $media->upload(
            'u1',
            'thumbnails.set',
            ['videoId' => 'aaaaaaaaaaa'],
            [],
            ['mime' => 'image/png', 'bytes' => 7, 'path' => 'fixture-image'],
            $step,
        ));

        expect($http->calls)->toBe(2);
        expect($receipt['status'])->toBe($expectedStatus);
        expect($cache->get('u1', 'video-cache'))->toBeNull();
        expect($vault->decrypt($receipt['completed']['upload-session']['session']))->toBe(['location' => $location]);
        expect($db->one('SELECT receipt FROM operations')['receipt'])->not->toContain($location);
        expect($receipts->run('u1', 'generation1', 'set_video_thumbnail', $arguments, fn() => $this->fail('Replayed upload')))
            ->toBe($receipt);
        if ($expectedStatus === 'uncertain') {
            expect($receipt['pending_step'])->toBe('upload-content');
            expect(fn() => $receipts->run('u1', 'generation1', 'set_video_thumbnail', $arguments + ['resume' => true], fn() => []))
                ->toThrow(ServiceError::class, 'automatic replay is disabled');
        } else {
            expect($receipt['result'])->toBe(['id' => 'aaaaaaaaaaa']);
        }
    } finally {
        $this->removeDir($directory);
    }
})->with([
    'success' => [200, '{"id":"aaaaaaaaaaa","ignored":"metadata"}', 'completed'],
    'incomplete upload' => [308, '', 'uncertain'],
    'upstream failure' => [503, '', 'uncertain'],
    'invalid success JSON' => [200, 'invalid', 'uncertain'],
]);
