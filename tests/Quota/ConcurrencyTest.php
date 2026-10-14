<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Quota;

use YouTubeMcp\Storage\Database;

test('separate php workers record usage without lost updates or local admission limits', function (): void {
    $dir = $this->tempDir();
    $db = new Database($dir . '/test.sqlite');
    $db->migrate(dirname(__DIR__, 2));
    unset($db);
    $ini = php_ini_loaded_file();
    $flags = [];
    if ($ini && !preg_match('/^\s*extension\s*=\s*(?:php_)?pdo_sqlite/im', file_get_contents($ini))) {
        $flags = ['-d', 'extension=pdo_sqlite'];
    }
    $workers = [];
    try {
        foreach (['a', 'b'] as $user) {
            $command = array_merge(
                [PHP_BINARY],
                $flags,
                [dirname(__DIR__) . '/concurrent-usage.php', $dir . '/test.sqlite', $user],
            );
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $workers[] = [$process, $pipes];
        }
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $results[] = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
        }
        $workers = [];
        sort($results);
        $this->assertSame(['recorded', 'recorded'], $results);
        $db = new Database($dir . '/test.sqlite');
        $usage = new \YouTubeMcp\Quota\Usage($db);
        $this->assertSame(1000, $usage->status('a')['buckets']['general']['project_spent']);
        foreach (['a', 'b'] as $user) {
            $this->assertSame(500, $usage->status($user)['buckets']['general']['user_spent']);
            $this->assertSame(10, (int) $db->one(
                'SELECT COUNT(*) AS n FROM quota_requests WHERE user_id=? AND operation_id=? AND state=?',
                [$user, 'fixture-' . $user, 'success'],
            )['n']);
        }
        unset($usage, $db);
    } finally {
        foreach ($workers as [$process, $pipes]) {
            foreach ($pipes as $p) {
                fclose($p);
            }
            proc_close($process);
        }
        $this->removeDir($dir);
    }
});
