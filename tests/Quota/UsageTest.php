<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Quota;

use YouTubeMcp\Quota\Usage;

covers(\YouTubeMcp\Quota\Usage::class);

test('legacy limits and breakers do not block requests', function (): void {
    $database = $this->database();
    $usage = new Usage($database);
    $database->execute(
        'INSERT INTO quota_circuit_breakers VALUES (?,?,?)',
        ['general', time() + 86400, 'YOUTUBE_QUOTA_EXHAUSTED'],
    );
    for ($requestPair = 0; $requestPair < 20; $requestPair++) {
        $usage->dispatch('a', 'playlistItems.insert', 'operation-fixture');
        $usage->dispatch('a', 'search.list');
    }

    $status = $usage->status('a');
    $this->assertSame(1000, $status['buckets']['general']['user_spent']);
    $this->assertSame(20, $status['buckets']['search']['user_spent']);
    $this->assertSame('youtube', $status['quota_enforced_by']);
    $this->assertFalse($status['remaining_quota_known']);
    $this->assertArrayNotHasKey('user_remaining', $status['buckets']['general']);
    $this->assertSame(20, (int) $database->one(
        'SELECT COUNT(*) AS n FROM quota_requests WHERE operation_id=?',
        ['operation-fixture'],
    )['n']);
});

test('reset tracks pacific daylight saving', function (): void {
    $clock = fn() => strtotime('2026-03-08T08:00:00Z');
    $usage = new Usage($this->database(), $clock);

    $this->assertSame('2026-03-08', $usage->window());
    $this->assertSame('2026-03-09T07:00:00+00:00', gmdate('c', $usage->reset()));
});

test('cleanup removes legacy gates without deleting usage', function (): void {
    $database = $this->database();
    $usage = new Usage($database);
    $usage->dispatch('a', 'videos.list');
    $database->execute(
        'INSERT INTO quota_reservations VALUES (?,?,?,?,?,?)',
        ['old', 'a', $usage->window(), 'general', 50, time() + 300],
    );

    $usage->cleanup();

    $reservationCount = $database->one('SELECT COUNT(*) AS n FROM quota_reservations')['n'];
    $status = $usage->status('a');
    $this->assertSame(0, $reservationCount);
    $this->assertSame(1, $status['buckets']['general']['user_spent']);
});
