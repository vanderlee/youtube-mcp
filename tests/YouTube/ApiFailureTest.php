<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\YouTube;

use YouTubeMcp\Http\Client;
use YouTubeMcp\Quota\Usage;
use YouTubeMcp\ServiceError;
use YouTubeMcp\YouTube\Api;

covers(Api::class);

test('upstream failures return after one dispatch for reads and writes', function (
    string $method,
    int $status,
    string $reason,
    string $error,
): void {
    $db = $this->database();
    $http = new class ($status, $reason) extends Client {
        public int $calls = 0;

        public function __construct(private int $status, private string $reason) {}

        public function request(string $method, string $url, array $headers = [], ?string $body = null): array
        {
            $this->calls++;
            return ['status' => $this->status, 'data' => ['error' => ['errors' => [['reason' => $this->reason]]]]];
        }
    };
    $api = new Api($http, new Usage($db), $db);
    try {
        $api->call('a', $method, operation: 'fixture-operation', token: 'test');
        $this->fail('Upstream failure must reach the caller.');
    } catch (ServiceError $failure) {
        expect($failure->reason)->toBe($error);
    }
    expect($http->calls)->toBe(1);
    expect($db->all('SELECT operation_id,state FROM quota_requests'))->toBe([
        ['operation_id' => 'fixture-operation', 'state' => 'rejected'],
    ]);
})->with([
    'server failure on read' => ['videos.list', 503, 'backendError', 'YOUTUBE_REQUEST_REJECTED'],
    'server failure on write' => ['videos.insert', 503, 'backendError', 'UPSTREAM_UNCERTAIN'],
    'quota error' => ['videos.list', 503, 'quotaExceeded', 'YOUTUBE_QUOTA_EXHAUSTED'],
    'rate error' => ['videos.list', 503, 'rateLimitExceeded', 'YOUTUBE_RATE_LIMITED'],
    'http throttling' => ['videos.list', 429, 'unknown', 'YOUTUBE_RATE_LIMITED'],
]);
