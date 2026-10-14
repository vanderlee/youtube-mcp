<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Auth;

use YouTubeMcp\Auth\Scope;

covers(Scope::class);

test('scope serialization preserves the exact granted permission', function (): void {
    $scope = new Scope('playlists.read');
    expect($scope->getIdentifier())->toBe('playlists.read');
    expect(json_encode($scope))->toBe('"playlists.read"');
    expect(Scope::SUPPORTED)->toContain('playlists.read', 'videos.upload');
    expect(Scope::SUPPORTED)->not->toContain('library.write');
});
