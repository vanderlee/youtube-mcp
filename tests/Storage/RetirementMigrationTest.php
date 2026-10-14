<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Storage;

use YouTubeMcp\Storage\Database;

covers(Database::class);

test(
    'retirement migration removes only music credentials and preserves official connections and receipts',
    function (): void {
        $db = new Database(':memory:');
        $db->execute('CREATE TABLE migrations (name TEXT PRIMARY KEY)');
        $root = dirname(__DIR__, 2);
        $sections = preg_split(
            '/^-- Migration: ([a-zA-Z0-9_]+\.sql)\h*\r?\n/m',
            file_get_contents($root . '/migrations/001_initial.sql'),
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );
        for ($index = 1; $index <= 7; $index += 2) {
            $name = $sections[$index];
            $db->pdo->exec($sections[$index + 1]);
            $db->execute('INSERT INTO migrations VALUES (?)', [$name]);
        }
        $this->user($db);
        $db->execute(
            'INSERT INTO google_connections VALUES (?,?,?,?,?,?)',
            ['u1', 'channel-u1', 'Fixture', 'dummy-encrypted-official', 'scope', 'generation'],
        );
        $db->execute(
            'INSERT INTO music_connections VALUES (?,?,?,?,?,?,?)',
            ['u1', 'channel-u1', 'generation', 'music-generation', 'hash', 'dummy-encrypted-music', 1],
        );
        $db->execute(
            'INSERT INTO music_browser_connections VALUES (?,?,?,?,?,?,?)',
            ['u1', 'channel-u1', 'generation', 'browser-generation', 'dummy-encrypted-cookie', '@fixture', 1],
        );
        $db->execute(
            'INSERT INTO oauth_tokens VALUES (?,?,?,?,?,?)',
            ['token', 'access', 'u1', 'client', time() + 3600, 0],
        );
        $db->execute(
            'INSERT INTO operations VALUES (?,?,?,?,?,?,?,?,?,?)',
            ['operation', 'u1', 'generation', 'add_tracks', 'key', 'hash', 'completed', '{}', 1, 1],
        );
        $db->migrate($root);
        $db->migrate($root);
        foreach (['music_connections', 'music_browser_connections'] as $table) {
            $this->assertNull($db->one("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$table]));
        }
        $this->assertSame(
            'dummy-encrypted-official',
            $db->one('SELECT tokens FROM google_connections WHERE user_id=?', ['u1'])['tokens'],
        );
        $this->assertSame(0, (int) $db->one('SELECT revoked FROM oauth_tokens WHERE id=?', ['token'])['revoked']);
        $this->assertSame('completed', $db->one('SELECT status FROM operations WHERE id=?', ['operation'])['status']);
    },
);
