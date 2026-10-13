<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use PHPUnit\Framework\TestCase;
use YouTubeMusicMcp\Operations\Receipts;
use YouTubeMusicMcp\ServiceError;
use YouTubeMusicMcp\Storage\Lock;

final class ReceiptsTest extends TestCase
{
    use Fixture;

    private string $dir;

    public function testReplayDoesNotExecuteAgainAndOtherUserCannotReadReceipt(): void
    {
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
        self::assertSame($a, $b);
        self::assertSame(1, $calls);
        $this->expectException(ServiceError::class);
        $r->get('another', $a['operation_id']);
    }

    public function testPartialResumeSkipsCompletedSteps(): void
    {
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
        self::assertSame('partial', $a['status']);
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
        self::assertSame('completed', $b['status']);
        self::assertSame(1, $calls);
    }

    public function testUncertainMutationCannotResume(): void
    {
        $db = $this->database();
        $this->user($db);
        $r = new Receipts($db, new Lock($this->dir));
        $r->run('u1', 'g', 'add', ['operation_key' => 'key-1234'], function ($step) {
            $step('one', fn() => throw new ServiceError('UPSTREAM_UNCERTAIN', 'timeout'));
        });
        $this->expectException(ServiceError::class);
        $r->run('u1', 'g', 'add', ['operation_key' => 'key-1234', 'resume' => true], fn() => self::fail());
    }

    public function testConflictingArgumentsCannotReuseKey(): void
    {
        $db = $this->database();
        $this->user($db);
        $r = new Receipts($db, new Lock($this->dir));
        $r->run('u1', 'g', 'create', ['operation_key' => 'key-1234', 'title' => 'a'], fn() => []);
        $this->expectException(ServiceError::class);
        $r->run('u1', 'g', 'create', ['operation_key' => 'key-1234', 'title' => 'b'], fn() => []);
    }

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }
}
