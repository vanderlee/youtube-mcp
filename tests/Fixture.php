<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use YouTubeMusicMcp\Config;
use YouTubeMusicMcp\Storage\Database;

trait Fixture
{
    private function database(): Database
    {
        $db = new Database(':memory:');
        $db->migrate(dirname(__DIR__));
        return $db;
    }

    private function config(array $values = []): Config
    {
        return new Config(
            $values + ['APP_URL' => 'https://music.example.test', 'APP_KEY' => base64_encode(str_repeat('k', 32))],
            dirname(__DIR__)
        );
    }

    private function user(Database $db, string $id = 'u1'): void
    {
        $db->execute('INSERT INTO users VALUES (?,?,?,?)', [$id, 'issuer|' . $id, $id . '@example.test', time()]);
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/yt-mcp-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        $resolved = str_replace('\\', '/', realpath($dir));
        $base = rtrim(str_replace('\\', '/', realpath(sys_get_temp_dir())), '/') . '/yt-mcp-';
        if (!str_starts_with(strtolower($resolved), strtolower($base))) {
            throw new \RuntimeException('Refusing cleanup outside test temporary directory.');
        }
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeDir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($dir);
    }
}
