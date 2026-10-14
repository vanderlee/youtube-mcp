<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests;

use YouTubeMcp\Operations\Receipts;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Lock;

trait ReceiptsTestHelpers
{
    private string $dir;


}

uses(ReceiptsTestHelpers::class);

test('replay does not execute again and other user cannot read receipt', function (): void {
    $db = $this->database();
    $this->user($db);
    $r = new Receipts($db, new Lock($this->dir));
    $calls = 0;
    $work = function ($step) use (&$calls) {
        return $step('one', function () use (&$calls) {
            return ++$calls;
        });
    };
    $a = $r->run('u1', 'g', 'create', ['operation_key' => 'key-1234'], $work);
    $b = $r->run('u1', 'g', 'create', ['operation_key' => 'key-1234'], $work);
    $this->assertSame($a, $b);
    $this->assertSame(1, $calls);
    $this->expectException(ServiceError::class);
    $r->get('another', $a['operation_id']);
});

test('partial resume skips completed steps', function (): void {
    $db = $this->database();
    $this->user($db);
    $r = new Receipts($db, new Lock($this->dir));
    $calls = 0;
    $a = $r->run('u1', 'g', 'add', ['operation_key' => 'key-1234'], function ($step) use (&$calls) {
        $step('one', function () use (&$calls) {
            return ++$calls;
        });
        throw new ServiceError('YOUTUBE_QUOTA_EXHAUSTED', 'wait');
    });
    $this->assertSame('partial', $a['status']);
    $b = $r->run(
        'u1',
        'g',
        'add',
        ['operation_key' => 'key-1234', 'resume' => true],
        function ($step) use (&$calls) {
            $step('one', function () use (&$calls) {
                return ++$calls;
            });
            return $step('two', fn() => 2);
        }
    );
    $this->assertSame('completed', $b['status']);
    $this->assertSame(1, $calls);
});

test('uncertain mutation cannot resume', function (): void {
    $db = $this->database();
    $this->user($db);
    $r = new Receipts($db, new Lock($this->dir));
    $r->run('u1', 'g', 'add', ['operation_key' => 'key-1234'], function ($step) {
        $step('one', fn() => throw new ServiceError('UPSTREAM_UNCERTAIN', 'timeout'));
    });
    $this->expectException(ServiceError::class);
    $r->run('u1', 'g', 'add', ['operation_key' => 'key-1234', 'resume' => true], fn() => $this->fail());
});

test('conflicting arguments cannot reuse key', function (): void {
    $db = $this->database();
    $this->user($db);
    $r = new Receipts($db, new Lock($this->dir));
    $r->run('u1', 'g', 'create', ['operation_key' => 'key-1234', 'title' => 'a'], fn() => []);
    $this->expectException(ServiceError::class);
    $r->run('u1', 'g', 'create', ['operation_key' => 'key-1234', 'title' => 'b'], fn() => []);
});

beforeEach(function (): void {
    $this->dir = $this->tempDir();
});

afterEach(function (): void {
    $this->removeDir($this->dir);
});
