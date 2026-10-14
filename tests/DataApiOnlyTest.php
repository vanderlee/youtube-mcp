<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use YouTubeMusicMcp\Auth\{OAuthRepository, Scope};
use YouTubeMusicMcp\Mcp\Tools;
use YouTubeMusicMcp\Storage\Database;

test('expanded catalog retains official tools and never restores retired music permissions', function (): void {
    $expected = ['list_liked_tracks', 'get_track_ratings', 'rate_track', 'list_subscriptions',
        'subscribe_artist', 'unsubscribe_artist', 'get_connection_status', 'get_usage_status', 'get_operation',
        'list_playlists', 'get_playlist', 'search_tracks', 'create_playlist', 'update_playlist', 'add_tracks',
        'remove_tracks', 'move_track', 'delete_playlist'];
    $actual = array_map(fn($tool) => $tool->name, Tools::definitions());
    sort($expected);
    sort($actual);
    $this->assertSame([], array_diff($expected, $actual));
    $this->assertCount(57, $actual);
    $this->assertCount(count($actual), array_unique($actual));
    foreach (Tools::definitions() as $tool) {
        $this->assertContains(\YouTubeMusicMcp\Playlist\Service::scope($tool->name), Scope::SUPPORTED);
    }
    $repo = new OAuthRepository($this->database(), 'https://music.example.test');
    foreach (['catalog.read', 'discovery.read', 'library.read', 'library.write', 'history.read', 'history.write'] as $scope) {
        $this->assertNull($repo->getScopeEntityByIdentifier($scope));
    }
});

test('retirement migration removes only music credentials and preserves official connections and receipts', function (): void {
    $db = new Database(':memory:');
    $db->execute('CREATE TABLE migrations (name TEXT PRIMARY KEY)');
    $root = dirname(__DIR__);
    foreach (['001_initial.sql', '002_quota_alerts.sql', '003_music_connections.sql', '004_music_browser_connections.sql'] as $name) {
        $db->pdo->exec(file_get_contents($root . '/migrations/' . $name));
        $db->execute('INSERT INTO migrations VALUES (?)', [$name]);
    }
    $this->user($db);
    $db->execute('INSERT INTO google_connections VALUES (?,?,?,?,?,?)', ['u1', 'channel-u1', 'Fixture', 'dummy-encrypted-official', 'scope', 'generation']);
    $db->execute('INSERT INTO music_connections VALUES (?,?,?,?,?,?,?)', ['u1', 'channel-u1', 'generation', 'music-generation', 'hash', 'dummy-encrypted-music', 1]);
    $db->execute('INSERT INTO music_browser_connections VALUES (?,?,?,?,?,?,?)', ['u1', 'channel-u1', 'generation', 'browser-generation', 'dummy-encrypted-cookie', '@fixture', 1]);
    $db->execute('INSERT INTO oauth_tokens VALUES (?,?,?,?,?,?)', ['token', 'access', 'u1', 'client', time() + 3600, 0]);
    $db->execute('INSERT INTO operations VALUES (?,?,?,?,?,?,?,?,?,?)', ['operation', 'u1', 'generation', 'add_tracks', 'key', 'hash', 'completed', '{}', 1, 1]);
    $db->migrate($root);
    $db->migrate($root);
    foreach (['music_connections', 'music_browser_connections'] as $table) {
        $this->assertNull($db->one("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$table]));
    }
    $this->assertSame('dummy-encrypted-official', $db->one('SELECT tokens FROM google_connections WHERE user_id=?', ['u1'])['tokens']);
    $this->assertSame(0, (int)$db->one('SELECT revoked FROM oauth_tokens WHERE id=?', ['token'])['revoked']);
    $this->assertSame('completed', $db->one('SELECT status FROM operations WHERE id=?', ['operation'])['status']);
});
