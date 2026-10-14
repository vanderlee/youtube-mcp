<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests;

use YouTubeMcp\Storage\Database;

test('merged migrations preserve every historical upgrade point', function (int $completed): void {
    $root = dirname(__DIR__);
    $db = new Database(':memory:');
    $db->execute('CREATE TABLE migrations (name TEXT PRIMARY KEY)');
    $parts = preg_split('/^-- Migration: ([a-zA-Z0-9_]+\.sql)\h*\r?\n/m', file_get_contents($root . '/migrations/001_initial.sql'), -1, PREG_SPLIT_DELIM_CAPTURE);
    for ($step = 0; $step < $completed; $step++) {
        $db->pdo->exec($parts[$step * 2 + 2]);
        $db->execute('INSERT INTO migrations VALUES (?)', [$parts[$step * 2 + 1]]);
    }
    if ($completed > 0) {
        $this->user($db);
    }

    $db->migrate($root);
    if ($completed === 0) {
        $this->user($db);
    }
    $db->migrate($root);

    $this->assertSame('u1', $db->one('SELECT id FROM users')['id']);
    $this->assertSame([
        '001_initial.sql', '002_quota_alerts.sql', '003_music_connections.sql',
        '004_music_browser_connections.sql', '005_remove_music_authentication.sql', '006_media_assets.sql',
    ], array_column($db->all('SELECT name FROM migrations ORDER BY name'), 'name'));
    $this->assertNotNull($db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='media_assets'"));
    $this->assertNotNull($db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='quota_alerts'"));
    $this->assertSame([], $db->all("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('music_connections', 'music_browser_connections')"));
    $this->assertSame([], $db->all('PRAGMA foreign_key_check'));
})->with([0, 1, 2, 3, 4, 5, 6]);

test('later standalone migrations work after the bundle and roll back failed SQL', function (): void {
    $root = $this->tempDir();
    mkdir($root . '/migrations');
    copy(dirname(__DIR__) . '/migrations/001_initial.sql', $root . '/migrations/001_initial.sql');
    file_put_contents($root . '/migrations/007_test.sql', 'CREATE TABLE migration_probe (id TEXT PRIMARY KEY);');
    file_put_contents($root . '/migrations/008_failure.sql', 'CREATE TABLE rollback_probe (id TEXT); INSERT INTO missing_table VALUES (1);');
    $db = new Database(':memory:');
    try {
        try {
            $db->migrate($root);
            $this->fail('Expected failed migration.');
        } catch (\PDOException) {
            $this->assertNotNull($db->one('SELECT name FROM migrations WHERE name=?', ['007_test.sql']));
            $this->assertNull($db->one('SELECT name FROM migrations WHERE name=?', ['008_failure.sql']));
            $this->assertNull($db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='rollback_probe'"));
        }
        file_put_contents($root . '/migrations/008_failure.sql', 'CREATE TABLE rollback_probe (id TEXT);');
        $db->migrate($root);
        $db->migrate($root);
        $this->assertSame(8, (int)$db->one('SELECT COUNT(*) AS n FROM migrations')['n']);
    } finally {
        $this->removeDir($root);
    }
});
