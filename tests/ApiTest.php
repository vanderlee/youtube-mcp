<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use PHPUnit\Framework\TestCase;
use YouTubeMusicMcp\Http\Client;
use YouTubeMusicMcp\Quota\Budget;
use YouTubeMusicMcp\ServiceError;
use YouTubeMusicMcp\YouTube\Api;

final class ApiTest extends TestCase
{
    use Fixture;

    public function testRatingRoutesAndEmptyWriteResponse(): void
    {
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
        self::assertSame([],
            $api->call('a', 'videos.rate', ['id' => 'aaaaaaaaaaa', 'rating' => 'like'], token: 'test')
        );
        self::assertSame(['GET', 'https://www.googleapis.com/youtube/v3/videos/getRating?id=aaaaaaaaaaa', null],
            $http->calls[0]);
        self::assertSame(['POST', 'https://www.googleapis.com/youtube/v3/videos/rate?id=aaaaaaaaaaa&rating=like', null],
            $http->calls[1]);
        self::assertSame(51, $budget->status('a')['buckets']['general']['user_spent']);
    }

    public function testCachedSearchDoesNotConsumeAnotherSearchCall(): void
    {
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
        self::assertSame(1, $http->calls);
        self::assertSame(1, $budget->status('a')['buckets']['search']['user_spent']);
    }

    public function testDailyExhaustionDoesNotRetry(): void
    {
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
                self::fail();
            } catch (ServiceError $e) {
                self::assertSame('YOUTUBE_QUOTA_EXHAUSTED', $e->reason);
            }
        }
        self::assertSame(2, $http->calls);
    }
}

