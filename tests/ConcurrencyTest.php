<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use YouTubeMusicMcp\Storage\Database;

test('separate php workers are not locally quota limited', function (): void {
    $dir = $this->tempDir();
    $db = new Database($dir . '/test.sqlite');
    $db->migrate(dirname(__DIR__));
    unset($db);
    $ini = php_ini_loaded_file();
    $flags = [];
    if ($ini && !preg_match('/^\s*extension\s*=\s*(?:php_)?pdo_sqlite/im', file_get_contents($ini))) {
        $flags = ['-d', 'extension=pdo_sqlite'];
    }
    $workers = [];
    try {
        foreach (['a', 'b'] as $user) {
            $command = array_merge([PHP_BINARY],
                $flags,
                [__DIR__ . '/concurrent-reserve.php', $dir . '/test.sqlite', $user]);
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
        $this->assertSame(['admitted', 'admitted'], $results);
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
