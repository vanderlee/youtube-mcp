<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\YouTube;

use YouTubeMcp\Http\Client;
use YouTubeMcp\Quota\Budget;
use YouTubeMcp\ServiceError;
use YouTubeMcp\YouTube\Api;

covers(Api::class);

test('response failures preserve bounded read retries and never retry writes', function (
    string $method,
    int $status,
    string $reason,
    ?string $error,
    int $expectedCalls,
): void {
    $db = $this->database();
    $http = new class ($status, $reason) extends Client {
        public int $calls = 0;

        public function __construct(private int $status, private string $reason) {}

        public function request(string $method, string $url, array $headers = [], ?string $body = null): array
        {
            $this->calls++;
            if ($this->reason === 'recover' && $this->calls === 3) {
                return ['status' => 200, 'data' => ['items' => []]];
            }

            return ['status' => $this->status, 'data' => ['error' => ['errors' => [['reason' => $this->reason]]]]];
        }
    };
    $api = new Api($http, new Budget($db, $this->config()), $db);
    try {
        $result = $api->call('a', $method, token: 'test');
        expect($error)->toBeNull();
        expect($result)->toBe(['items' => []]);
    } catch (ServiceError $e) {
        expect($e->reason)->toBe($error);
    }
    expect($http->calls)->toBe($expectedCalls);
})->with([
    'read recovers on final attempt' => ['videos.list', 503, 'recover', null, 3],
    'read exhausts attempts' => ['videos.list', 503, 'backendError', 'YOUTUBE_REQUEST_REJECTED', 3],
    'write remains uncertain' => ['videos.insert', 503, 'backendError', 'UPSTREAM_UNCERTAIN', 1],
    'quota takes precedence over retry' => ['videos.list', 503, 'quotaExceeded', 'YOUTUBE_QUOTA_EXHAUSTED', 1],
    'throttling takes precedence over retry' => ['videos.list', 503, 'rateLimitExceeded', 'YOUTUBE_RATE_LIMITED', 1],
    'http throttling' => ['videos.list', 429, 'unknown', 'YOUTUBE_RATE_LIMITED', 1],
]);
