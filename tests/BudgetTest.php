<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests;

use YouTubeMcp\Quota\Budget;

test('legacy limits and breakers do not block requests', function (): void {
    $db = $this->database();
    $b = new Budget(
        $db,
        $this->config(
            [
                'QUOTA_GENERAL' => '1',
                'USER_QUOTA_GENERAL' => '1',
                'USER_QUOTA_SEARCH' => '1',
                'QUOTA_METHOD_COSTS' => 'invalid'
            ]
        )
    );
    $db->execute(
        'INSERT INTO quota_circuit_breakers VALUES (?,?,?)',
        ['general', time() + 86400, 'YOUTUBE_QUOTA_EXHAUSTED']
    );
    $r = $b->reserve('a', 1000000);
    for ($n = 0; $n < 20; $n++) {
        $b->dispatch('a', 'playlistItems.insert', $r);
        $b->dispatch('a', 'search.list');
    }
    $this->assertSame(1000, $b->status('a')['buckets']['general']['user_spent']);
    $this->assertSame(20, $b->status('a')['buckets']['search']['user_spent']);
    $this->assertSame('youtube', $b->status('a')['quota_enforced_by']);
    $this->assertFalse($b->status('a')['remaining_quota_known']);
    $this->assertArrayNotHasKey('user_remaining', $b->status('a')['buckets']['general']);
});

test('reset tracks pacific daylight saving', function (): void {
    $b = new Budget($this->database(), $this->config(), fn() => strtotime('2026-03-08T08:00:00Z'));
    $this->assertSame('2026-03-08', $b->window());
    $this->assertSame('2026-03-09T07:00:00+00:00', gmdate('c', $b->reset()));
});

test('cleanup removes legacy gates without deleting usage', function (): void {
    $db = $this->database();
    $b = new Budget($db, $this->config());
    $b->dispatch('a', 'videos.list');
    $db->execute(
        'INSERT INTO quota_reservations VALUES (?,?,?,?,?,?)',
        ['old', 'a', $b->window(), 'general', 50, time() + 300]
    );
    $b->cleanup();
    $this->assertSame(0, $db->one('SELECT COUNT(*) AS n FROM quota_reservations')['n']);
    $this->assertSame(1, $b->status('a')['buckets']['general']['user_spent']);
});
