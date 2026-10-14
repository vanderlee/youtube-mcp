<?php

declare(strict_types=1);

namespace YouTubeMcp\YouTube;

use YouTubeMcp\Storage\Database;

/** Dependency versions stop a concurrent read from restoring an invalidated response. */
final class ResponseCache
{
    public function __construct(private Database $db)
    {
    }

    public function get(string $user, string $key): ?array
    {
        $row = $this->db->one('SELECT value FROM api_cache WHERE cache_key=? AND user_id=? AND expires_at>?', [$key, $user, time()]);

        return $row === null ? null : json_decode($row['value'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function tags(string $method, array $params): array
    {
        [$resource] = explode('.', $method);
        $ids = array_filter(explode(',', (string) ($params['id'] ?? '')));
        if ($method === 'videos.getRating') {
            return array_map(fn($id) => 'rating:' . $id, $ids);
        }
        return match ($resource) {
            'playlists' => ['playlist-data', ...($ids ? array_map(fn($id) => 'playlist:' . $id, $ids) : ['playlist-list'])],
            'playlistItems' => ['playlist-data', ...(isset($params['playlistId']) ? ['playlist:' . $params['playlistId']] : ['entry-lookup'])],
            'videos' => isset($params['myRating']) ? ['likes'] : array_map(fn($id) => 'video:' . $id, $ids),
            'subscriptions' => ['subscriptions'],
            'channels' => ['channels'],
            'comments', 'commentThreads' => ['comments'],
            'search' => ['search:' . ($params['type'] ?? 'video')],
            default => ['resource:' . $resource],
        };
    }

    public function versions(string $user, array $tags): array
    {
        $versions = [];
        foreach ($tags as $tag) {
            $versions[$tag] = (int) ($this->db->one('SELECT version FROM api_cache_versions WHERE user_id=? AND tag=?', [$user, $tag])['version'] ?? 0);
        }

        return $versions;
    }

    public function put(string $user, string $key, array $data, int $ttl, array $versions): void
    {
        $this->db->transaction(function () use ($user, $key, $data, $ttl, $versions): void {
            if ($versions !== $this->versions($user, array_keys($versions))) {
                return;
            }
            $this->db->execute('INSERT INTO api_cache(cache_key,user_id,value,expires_at) VALUES (?,?,?,?) ON CONFLICT(cache_key) DO UPDATE SET value=excluded.value,expires_at=excluded.expires_at',
                [$key, $user, json_encode($data, JSON_THROW_ON_ERROR), time() + $ttl]);
            $this->db->execute('DELETE FROM api_cache_tags WHERE cache_key=?', [$key]);
            foreach (array_keys($versions) as $tag) {
                $this->db->execute('INSERT INTO api_cache_tags VALUES (?,?)', [$key, $tag]);
            }
        });
    }

    public function invalidate(string $user, string $method, array $params, ?array $body, ?string $playlist): void
    {
        [$resource] = explode('.', $method);
        $id = $params['id'] ?? $body['id'] ?? null;
        $tags = $method === 'videos.rate' ? ['likes', 'rating:' . $id] : match ($resource) {
            'playlists' => ['playlist-list', 'search:playlist', ...($id ? ['playlist:' . $id] : [])],
            'playlistItems' => ['playlist-list', 'entry-lookup', ...($playlist ? ['playlist:' . $playlist] : ['playlist-data'])],
            'videos' => ['likes', 'search:video', 'playlist-data', 'channels', ...($id ? ['video:' . $id, 'rating:' . $id] : [])],
            'channels', 'channelBanners' => ['channels', 'search:channel'],
            'thumbnails' => ['search:video', ...(isset($params['videoId']) ? ['video:' . $params['videoId']] : [])],
            'subscriptions' => ['subscriptions'],
            'comments', 'commentThreads' => ['comments'],
            default => ['resource:' . $resource],
        };
        $this->db->transaction(function () use ($user, $tags): void {
            foreach ($tags as $tag) {
                $this->db->execute('INSERT INTO api_cache_versions VALUES (?,?,1) ON CONFLICT(user_id,tag) DO UPDATE SET version=version+1', [$user, $tag]);
                $this->db->execute('DELETE FROM api_cache WHERE user_id=? AND cache_key IN (SELECT cache_key FROM api_cache_tags WHERE tag=?)', [$user, $tag]);
            }
        });
    }
}
