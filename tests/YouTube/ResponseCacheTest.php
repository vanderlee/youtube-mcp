<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\YouTube;

use YouTubeMcp\YouTube\ResponseCache;

covers(\YouTubeMcp\YouTube\ResponseCache::class);

test('playlist invalidation preserves other playlists searches and other users', function (): void {
    $cache = new ResponseCache($this->database());
    foreach ([['a', 'first', ['playlist-data', 'playlist:P1']], ['a', 'second', ['playlist-data', 'playlist:P2']],
        ['a', 'search', ['search:video']], ['b', 'other-user', ['playlist-data', 'playlist:P1']]] as [$user, $key, $tags]) {
        $cache->put($user, $key, ['items' => [$key]], 30, $cache->versions($user, $tags));
    }

    $cache->invalidate('a', 'playlistItems.insert', [], ['snippet' => ['playlistId' => 'P1']], 'P1');

    expect($cache->get('a', 'first'))->toBeNull();
    expect($cache->get('a', 'second'))->not->toBeNull();
    expect($cache->get('a', 'search'))->not->toBeNull();
    expect($cache->get('b', 'other-user'))->not->toBeNull();
});

test('an in flight read cannot restore an invalidated cache entry', function (): void {
    $cache = new ResponseCache($this->database());
    $snapshot = $cache->versions('a', ['playlist-data', 'playlist:P1']);

    $cache->invalidate('a', 'playlists.update', ['id' => 'P1'], null, null);
    $cache->put('a', 'stale', ['title' => 'old'], 30, $snapshot);

    expect($cache->get('a', 'stale'))->toBeNull();
    $cache->put('a', 'fresh', ['title' => 'new'], 30, $cache->versions('a', array_keys($snapshot)));
    expect($cache->get('a', 'fresh'))->toBe(['title' => 'new']);
});

test('unknown playlist item deletion invalidates playlist data conservatively', function (): void {
    $cache = new ResponseCache($this->database());
    $cache->put('a', 'playlist', [], 30, $cache->versions('a', ['playlist-data', 'playlist:P1']));
    $cache->put('a', 'subscriptions', [], 30, $cache->versions('a', ['subscriptions']));

    $cache->invalidate('a', 'playlistItems.delete', ['id' => 'unknown'], null, null);

    expect($cache->get('a', 'playlist'))->toBeNull();
    expect($cache->get('a', 'subscriptions'))->toBe([]);
});

test('rating changes retain video metadata and search but clear ratings and likes', function (): void {
    $cache = new ResponseCache($this->database());
    foreach (['metadata' => 'video:v1', 'search' => 'search:video', 'rating' => 'rating:v1', 'likes' => 'likes'] as $key => $tag) {
        $cache->put('a', $key, [], 30, $cache->versions('a', [$tag]));
    }
    $cache->invalidate('a', 'videos.rate', ['id' => 'v1'], null, null);
    expect($cache->get('a', 'metadata'))->toBe([]);
    expect($cache->get('a', 'search'))->toBe([]);
    expect($cache->get('a', 'rating'))->toBeNull();
    expect($cache->get('a', 'likes'))->toBeNull();
});
