<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use YouTubeMusicMcp\Http\Client;
use YouTubeMusicMcp\Quota\Budget;
use YouTubeMusicMcp\ServiceError;
use YouTubeMusicMcp\YouTube\Api;

test('rating routes and empty write response', function (): void {
    $db = $this->database();
    $budget = new Budget($db, $this->config());
    $http = new class extends Client {
        public array $calls = [];

        public function request(string $method, string $url, array $headers = [], ?string $body = null): array
        {
            $this->calls[] = [$method, $url, $body];
            return ['status' => $method === 'POST' ? 204 : 200, 'data' => []];
        }
    };
    $api = new Api($http, $budget, $db);
    $api->call('a', 'videos.getRating', ['id' => 'aaaaaaaaaaa'], token: 'test');
    $this->assertSame([],
        $api->call('a', 'videos.rate', ['id' => 'aaaaaaaaaaa', 'rating' => 'like'], token: 'test')
    );
    $this->assertSame(['GET', 'https://www.googleapis.com/youtube/v3/videos/getRating?id=aaaaaaaaaaa', null],
        $http->calls[0]);
    $this->assertSame(['POST', 'https://www.googleapis.com/youtube/v3/videos/rate?id=aaaaaaaaaaa&rating=like', null],
        $http->calls[1]);
    $this->assertSame(51, $budget->status('a')['buckets']['general']['user_spent']);
});

test('cached search does not consume another search call', function (): void {
    $db = $this->database();
    $budget = new Budget($db, $this->config());
    $http = new class extends Client {
        public int $calls = 0;

        public function request(string $method, string $url, array $headers = [], ?string $body = null): array
        {
            $this->calls++;
            return ['status' => 200, 'data' => ['items' => []]];
        }
    };
    $api = new Api($http, $budget, $db);
    $api->call('a', 'search.list', ['q' => 'song'], token: 'test', ttl: 300);
    $api->call('a', 'search.list', ['q' => 'song'], token: 'test', ttl: 300);
    $this->assertSame(1, $http->calls);
    $this->assertSame(1, $budget->status('a')['buckets']['search']['user_spent']);
});

test('daily exhaustion does not retry', function (): void {
    $db = $this->database();
    $budget = new Budget($db, $this->config());
    $http = new class extends Client {
        public int $calls = 0;

        public function request(string $method, string $url, array $headers = [], ?string $body = null): array
        {
            $this->calls++;
            return ['status' => 403, 'data' => ['error' => ['errors' => [['reason' => 'quotaExceeded']]]]];
        }
    };
    $api = new Api($http, $budget, $db);
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
