<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\YouTube;

use YouTubeMcp\Auth\{Google, Vault};
use YouTubeMcp\Http\Client;
use YouTubeMcp\Quota\Usage;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Lock;
use YouTubeMcp\YouTube\Api;

covers(\YouTubeMcp\YouTube\Api::class);

test('rating routes and empty write response', function (): void {
    $db = $this->database();
    $usage = new Usage($db);
    $http = new class extends Client {
        public array $calls = [];

        public function request(string $method, string $url, array $headers = [], ?string $body = null): array
        {
            $this->calls[] = [$method, $url, $body];
            return ['status' => $method === 'POST' ? 204 : 200, 'data' => []];
        }
    };
    $api = new Api($http, $usage, $db);
    $api->call('a', 'videos.getRating', ['id' => 'aaaaaaaaaaa'], token: 'test');
    $this->assertSame(
        [],
        $api->call('a', 'videos.rate', ['id' => 'aaaaaaaaaaa', 'rating' => 'like'], token: 'test'),
    );
    $this->assertSame(
        ['GET', 'https://www.googleapis.com/youtube/v3/videos/getRating?id=aaaaaaaaaaa', null],
        $http->calls[0],
    );
    $this->assertSame(
        ['POST', 'https://www.googleapis.com/youtube/v3/videos/rate?id=aaaaaaaaaaa&rating=like', null],
        $http->calls[1],
    );
    $this->assertSame(51, $usage->status('a')['buckets']['general']['user_spent']);
});

test('cached search does not consume another search call', function (): void {
    $db = $this->database();
    $usage = new Usage($db);
    $http = new class extends Client {
        public int $calls = 0;

        public function request(string $method, string $url, array $headers = [], ?string $body = null): array
        {
            $this->calls++;
            return ['status' => 200, 'data' => ['items' => []]];
        }
    };
    $api = new Api($http, $usage, $db);
    $api->call('a', 'search.list', ['q' => 'song', 'part' => 'snippet'], token: 'test', ttl: 300);
    $api->call('a', 'search.list', ['part' => 'snippet', 'q' => 'song'], token: 'test', ttl: 300);
    $this->assertSame(1, $http->calls);
    $this->assertSame(1, $usage->status('a')['buckets']['search']['user_spent']);
});

test('daily exhaustion does not retry', function (): void {
    $db = $this->database();
    $usage = new Usage($db);
    $http = new class extends Client {
        public int $calls = 0;

        public function request(string $method, string $url, array $headers = [], ?string $body = null): array
        {
            $this->calls++;
            return ['status' => 403, 'data' => ['error' => ['errors' => [['reason' => 'quotaExceeded']]]]];
        }
    };
    $api = new Api($http, $usage, $db);
    for ($n = 0; $n < 2; $n++) {
        try {
            $api->call('a', 'search.list', token: 'test');
            $this->fail();
        } catch (ServiceError $e) {
            $this->assertSame('YOUTUBE_QUOTA_EXHAUSTED', $e->reason);
        }
    }
    $this->assertSame(2, $http->calls);
});

test('new read cache skips network calls while default mutation checks stay fresh', function (): void {
    $db = $this->database();
    $http = new class extends Client {
        public int $calls = 0;

        public function request(string $method, string $url, array $headers = [], ?string $body = null): array
        {
            $this->calls++;
            return ['status' => 200, 'data' => ['items' => []]];
        }
    };
    $api = new Api($http, new Usage($db), $db);
    $params = ['part' => 'snippet', 'id' => 'P1'];
    $api->call('a', 'playlists.list', $params, token: 'test', ttl: 30);
    $api->call('a', 'playlists.list', $params, token: 'test', ttl: 30);
    $api->call('a', 'playlists.list', $params, token: 'test');
    expect($http->calls)->toBe(2);
});

test('failed and uncertain writes invalidate dependent caches but preserve search', function (): void {
    $db = $this->database();
    $http = new class extends Client {
        public array $methods = [];

        public function request(string $method, string $url, array $headers = [], ?string $body = null): array
        {
            $this->methods[] = $method;
            if ($method !== 'GET') {
                throw new ServiceError('UPSTREAM_UNCERTAIN', 'test timeout');
            }
            return ['status' => 200, 'data' => ['items' => []]];
        }
    };
    $api = new Api($http, new Usage($db), $db);
    $api->call('a', 'playlists.list', ['id' => 'P1'], token: 'test', ttl: 30);
    $api->call('a', 'search.list', ['q' => 'song'], token: 'test', ttl: 30);
    try {
        $api->call('a', 'playlists.update', [], ['id' => 'P1'], token: 'test');
        $this->fail();
    } catch (ServiceError $e) {
        expect($e->reason)->toBe('UPSTREAM_UNCERTAIN');
    }
    $api->call('a', 'search.list', ['q' => 'song'], token: 'test', ttl: 30);
    $api->call('a', 'playlists.list', ['id' => 'P1'], token: 'test', ttl: 30);
    expect($http->methods)->toBe(['GET', 'GET', 'PUT', 'GET']);
});

test('lookup maps a deleted occurrence to its playlist without clearing unrelated metadata', function (): void {
    $db = $this->database();
    $http = new class extends Client {
        public int $calls = 0;

        public function request(string $method, string $url, array $headers = [], ?string $body = null): array
        {
            $this->calls++;
            return [
                'status' => 200,
                'data' => str_contains($url, 'playlistItems?part=snippet')
                    ? ['items' => [['id' => 'entry1', 'snippet' => ['playlistId' => 'P1']]]]
                    : ['items' => []],
            ];
        }
    };
    $api = new Api($http, new Usage($db), $db);
    $api->call('a', 'playlists.list', ['id' => 'P1'], token: 'test', ttl: 30);
    $api->call('a', 'playlists.list', ['id' => 'P2'], token: 'test', ttl: 30);
    $api->call('a', 'playlistItems.list', ['part' => 'snippet', 'id' => 'entry1'], token: 'test');
    $api->call('a', 'playlistItems.delete', ['id' => 'entry1'], token: 'test');
    $api->call('a', 'playlists.list', ['id' => 'P2'], token: 'test', ttl: 30);
    $api->call('a', 'playlists.list', ['id' => 'P1'], token: 'test', ttl: 30);
    expect($http->calls)->toBe(5);
});

test('configured read ttl caches confirmed connections and zero disables additional reads', function (): void {
    $db = $this->database();
    $this->user($db);
    $config = $this->config();
    $vault = new Vault($config->get('APP_KEY'));
    $db->execute('INSERT INTO google_connections VALUES (?,?,?,?,?,?)', [
        'u1',
        'channel1',
        'Test',
        $vault->encrypt(['access_token' => 'dummy-token', 'expires_at' => time() + 3600]),
        'scope',
        'generation1',
    ]);
    $directory = $this->tempDir();
    try {
        $http = new class extends Client {
            public int $calls = 0;

            public function request(string $method, string $url, array $headers = [], ?string $body = null): array
            {
                $this->calls++;
                return ['status' => 200, 'data' => ['items' => []]];
            }
        };
        $google = new Google($config, $db, $vault, $http, new Lock($directory));
        $cached = new Api($http, new Usage($db), $db, $google, 30);
        $cached->read('u1', 'channels.list', ['id' => 'channel1']);
        $cached->read('u1', 'channels.list', ['id' => 'channel1']);
        expect($http->calls)->toBe(1);
        $fresh = new Api($http, new Usage($db), $db, $google, 0);
        $fresh->read('u1', 'channels.list', ['id' => 'channel1']);
        $fresh->read('u1', 'channels.list', ['id' => 'channel1']);
        expect($http->calls)->toBe(3);
        $db->execute('UPDATE google_connections SET generation=? WHERE user_id=?', ['generation2', 'u1']);
        $cached->read('u1', 'channels.list', ['id' => 'channel1']);
        expect($http->calls)->toBe(4);
    } finally {
        $this->removeDir($directory);
    }
});
