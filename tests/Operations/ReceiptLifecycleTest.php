<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Operations;

use RuntimeException;
use YouTubeMcp\Operations\Receipts;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Lock;

covers(Receipts::class);

test('an interrupted effect retains its persisted pending step and blocks resume', function (): void {
    $db = $this->database();
    $this->user($db);
    $directory = $this->tempDir();
    try {
        $receipts = new Receipts($db, new Lock($directory));
        $arguments = ['operation_key' => 'interrupted-effect'];
        $work = function ($step) use ($db) {
            $step('completed', fn() => ['id' => 'first']);
            $step('interrupted', function () use ($db) {
                $saved = json_decode($db->one('SELECT receipt FROM operations')['receipt'], true);
                expect($saved['status'])->toBe('running');
                expect($saved['pending_step'])->toBe('interrupted');
                expect($saved['completed'])->toBe(['completed' => ['id' => 'first']]);

                throw new RuntimeException('Fixture interruption');
            });
        };
        expect(fn() => $receipts->run('u1', 'g1', 'add_tracks', $arguments, $work))
            ->toThrow(RuntimeException::class, 'Fixture interruption');

        $row = $db->one('SELECT status,receipt FROM operations');
        $saved = json_decode($row['receipt'], true);
        expect($row['status'])->toBe('uncertain');
        expect($saved['pending_step'])->toBe('interrupted');
        expect($saved['error']['code'])->toBe('INTERNAL_ERROR');
        expect($receipts->run('u1', 'g1', 'add_tracks', $arguments, fn() => $this->fail('Replayed effect')))
            ->toBe($saved);
        expect(fn() => $receipts->run('u1', 'g1', 'add_tracks', $arguments + ['resume' => true], fn() => []))
            ->toThrow(ServiceError::class, 'automatic replay is disabled');
    } finally {
        $this->removeDir($directory);
    }
});

test('receipt failures preserve completed steps and distinguish uncertain from partial work', function (string $reason, string $status): void {
    $db = $this->database();
    $this->user($db);
    $directory = $this->tempDir();
    try {
        $receipts = new Receipts($db, new Lock($directory));
        $receipt = $receipts->run('u1', 'g1', 'add_tracks', ['operation_key' => 'failed-effect'], function ($step) use ($reason) {
            $step('first', fn() => ['id' => 'entry1']);
            $step('second', fn() => throw new ServiceError($reason, 'Fixture failure'));
        });
        expect($receipt['status'])->toBe($status);
        expect($receipt['completed'])->toBe(['first' => ['id' => 'entry1']]);
        expect(isset($receipt['pending_step']))->toBe($status === 'uncertain');
        expect($db->one('SELECT outcome FROM audit_events')['outcome'])->toBe($status);
    } finally {
        $this->removeDir($directory);
    }
})->with([
    ['UPSTREAM_UNCERTAIN', 'uncertain'],
    ['YOUTUBE_RATE_LIMITED', 'partial'],
]);
