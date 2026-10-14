<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Deployment;

use YouTubeMcp\Storage\Database;
use YouTubeMcp\Storage\Lock;
use YouTubeMcp\YouTube\MediaStore;

covers(MediaStore::class);

test('cleanup defers busy assets while removing other expired media and continuing housekeeping', function (): void {
    $directory = $this->tempDir();
    try {
        mkdir($directory . '/bin');
        mkdir($directory . '/vendor');
        mkdir($directory . '/media');
        copy(dirname(__DIR__, 2) . '/bin/console.php', $directory . '/bin/console.php');
        file_put_contents($directory . '/vendor/autoload.php', '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        file_put_contents($directory . '/.env', 'STORAGE_PATH="' . str_replace('\\', '/', $directory) . '"' . "\n");
        $db = new Database($directory . '/service.sqlite');
        $db->migrate(dirname(__DIR__, 2));
        foreach (['u1' => 'a', 'u2' => 'b'] as $user => $character) {
            $this->user($db, $user);
            $id = str_repeat($character, 48);
            file_put_contents($directory . '/media/' . $id, 'fixture');
            $db->execute('INSERT INTO media_assets VALUES (?,?,?,?,?,?,?,?)', [
                $id, $user, 'channel', 'generation', 'text/vtt', 7, hash('sha256', 'fixture'), time() - 86401,
            ]);
        }
        $orphan = $directory . '/media/' . str_repeat('c', 48);
        file_put_contents($orphan, 'fixture');
        touch($orphan, time() - 86401);
        $db->execute('INSERT INTO api_cache VALUES (?,?,?,?)', ['expired', 'u1', '{}', time() - 1]);
        $locks = new Lock($directory . '/locks');
        $locks->run('account:u1', function () use ($directory, $db, $orphan): void {
            $command = [PHP_BINARY, '-d', 'extension=pdo_sqlite', $directory . '/bin/console.php', 'cleanup'];
            $process = proc_open(
                $command,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                array_merge(getenv(), ['STORAGE_PATH' => $directory]),
            );
            expect($process)->toBeResource();
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect(proc_close($process))->toBe(0, $error);
            expect($output)->toContain('deferred 1 expired asset(s)', 'Cleanup complete');
            expect(is_file($directory . '/media/' . str_repeat('a', 48)))->toBeTrue();
            expect(is_file($directory . '/media/' . str_repeat('b', 48)))->toBeFalse();
            expect(is_file($orphan))->toBeFalse();
            expect($db->all('SELECT user_id FROM media_assets'))->toBe([['user_id' => 'u1']]);
            expect($db->all('SELECT cache_key FROM api_cache'))->toBe([]);
        });
        $store = new MediaStore($db, $this->config(['STORAGE_PATH' => $directory]));
        expect($store->cleanup($locks))->toBe(0);
        expect($db->all('SELECT id FROM media_assets'))->toBe([]);
        expect(is_file($directory . '/media/' . str_repeat('a', 48)))->toBeFalse();
    } finally {
        unset($db, $store);
        $this->removeDir($directory);
    }
});
