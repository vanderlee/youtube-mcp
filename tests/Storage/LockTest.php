<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Storage;

use RuntimeException;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Lock;

covers(Lock::class);

test('locks exclude independent owners and release after callback failures', function (): void {
    $directory = $this->tempDir();
    try {
        $owner = new Lock($directory);
        $other = new Lock($directory);
        $owner->run('playlist:test', function () use ($owner, $other): void {
            expect($owner->run('playlist:test', fn () => 'nested'))->toBe('nested');
            expect(fn () => $other->run('playlist:test', fn () => 'forbidden'))->toThrow(ServiceError::class);
        });
        expect(fn () => $owner->run('playlist:test', function (): void {
            throw new RuntimeException('failure');
        }))->toThrow(RuntimeException::class);
        expect($other->run('playlist:test', fn () => 'released'))->toBe('released');
    } finally {
        $this->removeDir($directory);
    }
});
