<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Quota;

use YouTubeMcp\Quota\Budget;

covers(\YouTubeMcp\Quota\Budget::class);

test('legacy limits and breakers do not block requests', function (): void {
    $database = $this->database();
    $config = $this->config([
        'QUOTA_GENERAL' => '1',
        'USER_QUOTA_GENERAL' => '1',
        'USER_QUOTA_SEARCH' => '1',
        'QUOTA_METHOD_COSTS' => 'invalid',
    ]);
    $budget = new Budget($database, $config);
    $database->execute(
        'INSERT INTO quota_circuit_breakers VALUES (?,?,?)',
        ['general', time() + 86400, 'YOUTUBE_QUOTA_EXHAUSTED'],
    );
    $reservationId = $budget->reserve('a', 1000000);
    for ($requestPair = 0; $requestPair < 20; $requestPair++) {
        $budget->dispatch('a', 'playlistItems.insert', $reservationId);
        $budget->dispatch('a', 'search.list');
    }

    $status = $budget->status('a');
    $this->assertSame(1000, $status['buckets']['general']['user_spent']);
    $this->assertSame(20, $status['buckets']['search']['user_spent']);
    $this->assertSame('youtube', $status['quota_enforced_by']);
    $this->assertFalse($status['remaining_quota_known']);
    $this->assertArrayNotHasKey('user_remaining', $status['buckets']['general']);
});

test('reset tracks pacific daylight saving', function (): void {
    $clock = fn() => strtotime('2026-03-08T08:00:00Z');
    $budget = new Budget($this->database(), $this->config(), $clock);

    $this->assertSame('2026-03-08', $budget->window());
    $this->assertSame('2026-03-09T07:00:00+00:00', gmdate('c', $budget->reset()));
});

test('cleanup removes legacy gates without deleting usage', function (): void {
    $database = $this->database();
    $budget = new Budget($database, $this->config());
    $budget->dispatch('a', 'videos.list');
    $database->execute(
        'INSERT INTO quota_reservations VALUES (?,?,?,?,?,?)',
        ['old', 'a', $budget->window(), 'general', 50, time() + 300],
    );

    $budget->cleanup();

    $reservationCount = $database->one('SELECT COUNT(*) AS n FROM quota_reservations')['n'];
    $status = $budget->status('a');
    $this->assertSame(0, $reservationCount);
    $this->assertSame(1, $status['buckets']['general']['user_spent']);
});
