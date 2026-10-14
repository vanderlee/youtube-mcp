<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Mcp;

use YouTubeMcp\Auth\{OAuthRepository, Scope};
use YouTubeMcp\Mcp\Tools;

covers(\YouTubeMcp\Mcp\Tools::class);

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
        $this->assertContains(\YouTubeMcp\Playlist\Service::scope($tool->name), Scope::SUPPORTED);
    }
    $repo = new OAuthRepository($this->database(), 'https://music.example.test');
    foreach (['catalog.read', 'discovery.read', 'library.read', 'library.write', 'history.read', 'history.write'] as $scope) {
        $this->assertNull($repo->getScopeEntityByIdentifier($scope));
    }
});
