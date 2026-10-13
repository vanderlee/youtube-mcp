<?php

declare(strict_types=1);

namespace YouTubeMcp\Playlist;

use YouTubeMcp\Auth\Google;
use YouTubeMcp\Operations\Receipts;
use YouTubeMcp\Quota\Budget;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Lock;
use YouTubeMcp\YouTube\Api;

final class Service
{
    public const WRITES = [
        'rate_track',
        'subscribe_artist',
        'unsubscribe_artist',
        'create_playlist',
        'update_playlist',
        'add_tracks',
        'remove_tracks',
        'move_track',
        'delete_playlist'
    ];

    public static function scope(string $tool): string
    {
        if (isset(\YouTubeMcp\Music\Tools::specs()[$tool])) { return \YouTubeMcp\Music\Tools::scope($tool); }
        $group = match ($tool) {
            'list_liked_tracks', 'get_track_ratings', 'rate_track' => 'likes',
            'list_subscriptions', 'subscribe_artist', 'unsubscribe_artist' => 'subscriptions',
            default => 'playlists'
        };
        return $group . (in_array($tool, self::WRITES, true) ? '.write' : '.read');
    }

    public function __construct(
        private Google $google,
        private Api $api,
        private Budget $budget,
        private Receipts $receipts,
        private Lock $locks,
        private ?\YouTubeMcp\Music\Service $music = null
    ) {
    }

    public function execute(string $user, array $scopes, string $tool, array $a): array
    {
        if (isset(\YouTubeMcp\Music\Tools::specs()[$tool])) {
            if (!$this->music) { throw new ServiceError('MUSIC_SETUP_REQUIRED', 'Music integration is not configured.', [], 503); }
            return $this->music->execute($user, $scopes, $tool, $a);
        }
        $write = in_array($tool, self::WRITES, true);
        if (!in_array(self::scope($tool), $scopes, true)) {
            throw new ServiceError('INSUFFICIENT_SERVICE_SCOPE', 'Authorize the required permission: ' . self::scope($tool), [], 403
            );
        }
        if ($tool === 'get_usage_status') {
            return $this->budget->status($user);
        }
        if ($tool === 'get_operation') {
            return $this->receipts->get($user, $a['operation_id']);
        }
        $c = $this->google->connection($user);
        if ($tool === 'get_connection_status') {
            return ['connected' => true, 'channel_id' => $c['channel_id'], 'channel_name' => $c['channel_name']];
        }
        if ($write) {
            return $this->locks->run('account:' . $user, function () use ($user, $tool, $a) {
                $c = $this->google->connection($user);
                return $this->locks->run(
                    'playlist:' . $user . ':' . ($a['playlist_id'] ?? 'create'),
                    fn() => $this->receipts->run(
                        $user,
                        $c['generation'],
                        $tool,
                        $a,
                        fn($step, $id, $done) => $this->mutate($user, $c, $tool, $a, $step, $id, $done)
                    )
                );
            });
        }
        $page = ['maxResults' => $a['page_size'] ?? 25];
        if (!empty($a['page_token'])) {
            $page['pageToken'] = $a['page_token'];
        }
        if ($tool === 'list_playlists') {
            return $this->api->call(
                $user,
                'playlists.list',
                ['part' => 'snippet,status,contentDetails', 'mine' => 'true'] + $page,
                ttl: 30
            );
        }
        if ($tool === 'list_liked_tracks') {
            $r = $this->api->call($user, 'videos.list', ['part' => 'snippet,contentDetails', 'myRating' => 'like'] + $page, ttl: 30);
            return $r + ['music_availability' => 'YouTube liked videos; may include non-music content. Music playback is not guaranteed.'];
        }
        if ($tool === 'get_track_ratings') {
            $ids = array_map(fn($v) => $this->video($v), $a['video_ids']);
            return $this->api->call($user, 'videos.getRating', ['id' => implode(',', array_unique($ids))], ttl: 15);
        }
        if ($tool === 'list_subscriptions') {
            return $this->api->call($user, 'subscriptions.list', ['part' => 'snippet', 'mine' => 'true'] + $page, ttl: 30);
        }
        if ($tool === 'get_playlist') {
            $metadata = $this->metadata($user, $a['playlist_id']);
            $contents = $this->api->call(
                $user,
                'playlistItems.list',
                ['part' => 'snippet,contentDetails', 'playlistId' => $a['playlist_id']] + $page,
                ttl: 15
            );
            return [
                'playlist' => $metadata,
                'entries' => $contents['items'] ?? [],
                'next_page_token' => $contents['nextPageToken'] ?? null,
                'links' => $this->links($a['playlist_id'])
            ];
        }
        if ($tool === 'search_tracks') {
            $query = ['part' => 'snippet', 'type' => 'video', 'q' => $a['query'], 'maxResults' => $a['limit'] ?? 10];
            foreach (['region' => 'regionCode', 'page_token' => 'pageToken'] as $arg => $param) {
                if (!empty($a[$arg])) {
                    $query[$param] = $a[$arg];
                }
            }
            $results = $this->api->call($user, 'search.list', $query, ttl: 300);
            $ids = array_column(array_column($results['items'] ?? [], 'id'), 'videoId');
            $videos = $ids ? $this->api->call(
                $user,
                'videos.list',
                ['part' => 'snippet,contentDetails,status', 'id' => implode(',', $ids)],
                ttl: 300
            ) : ['items' => []];
            return [
                'candidates' => array_map(
                    fn($v) => [
                        'video_id' => $v['id'],
                        'title' => $v['snippet']['title'],
                        'channel' => $v['snippet']['channelTitle'],
                        'duration' => $v['contentDetails']['duration'] ?? null,
                        'url' => 'https://www.youtube.com/watch?v=' . $v['id']
                    ],
                    $videos['items'] ?? []
                ),
                'next_page_token' => $results['nextPageToken'] ?? null,
                'music_availability' => 'These are YouTube video candidates; YouTube Music playback is not guaranteed.'
            ];
        }
        throw new ServiceError('UNKNOWN_TOOL', 'Unknown tool.');
    }

    private function mutate(
        string $user,
        array $connection,
        string $tool,
        array $a,
        callable $step,
        string $op,
        array $done
    ): array {
        if (in_array($tool, ['rate_track', 'subscribe_artist', 'unsubscribe_artist'], true)) {
            return $this->mutatePreference($user, $tool, $a, $step, $op, $done);
        }
        $id = $a['playlist_id'] ?? null;
        $metadata = null;
        $entries = [];
        $planned = [];
        if ($id) {
            $metadata = $this->metadata($user, $id);
            if (($metadata['snippet']['channelId'] ?? null) !== $connection['channel_id']) {
                throw new ServiceError(
                    'NOT_PLAYLIST_OWNER',
                    'Only playlists owned by your connected channel may be changed.',
                    [],
                    403
                );
            }
        }
        if ($tool === 'create_playlist') {
            $planned[] = [
                'create',
                'playlists.insert',
                ['part' => 'snippet,status'],
                [
                    'snippet' => ['title' => $a['title'], 'description' => $a['description'] ?? ''],
                    'status' => ['privacyStatus' => $a['privacy'] ?? 'private']
                ]
            ];
        } elseif ($tool === 'update_playlist') {
            $snippet = $metadata['snippet'];
            unset($snippet['thumbnails'], $snippet['channelId'], $snippet['channelTitle'], $snippet['publishedAt'], $snippet['localized']);
            $snippet = array_intersect_key($snippet, array_flip(['title', 'description', 'tags', 'defaultLanguage']));
            foreach (['title', 'description'] as $field) {
                if (array_key_exists($field, $a)) {
                    $snippet[$field] = $a[$field];
                }
            }
            $body = [
                'id' => $id,
                'snippet' => $snippet,
                'status' => ['privacyStatus' => $a['privacy'] ?? $metadata['status']['privacyStatus']]
            ];
            $planned[] = ['update', 'playlists.update', ['part' => 'snippet,status'], $body];
        } elseif ($tool === 'delete_playlist') {
            $planned[] = ['delete', 'playlists.delete', ['id' => $id], null];
        } elseif ($tool === 'add_tracks') {
            $videos = array_map(fn($v) => $this->video($v), $a['video_ids']);
            $policy = $a['duplicate_policy'] ?? 'skip';
            $present = $policy === 'skip' ? array_column(
                array_column($this->entries($user, $id), 'snippet'),
                'resourceId'
            ) : [];
            $seen = array_fill_keys(array_column($present, 'videoId'), true);
            foreach ($videos as $n => $video) {
                if (isset($done['add:' . $n])) {
                    continue;
                }
                if ($policy === 'skip' && isset($seen[$video])) {
                    continue;
                }
                $seen[$video] = true;
                $planned[] = [
                    'add:' . $n,
                    'playlistItems.insert',
                    ['part' => 'snippet'],
                    [
                        'snippet' => [
                            'playlistId' => $id,
                            'resourceId' => ['kind' => 'youtube#video', 'videoId' => $video]
                        ]
                    ]
                ];
            }
        } elseif ($tool === 'remove_tracks' || $tool === 'move_track') {
            $entries = $this->entries($user, $id);
            $byId = array_column($entries, null, 'id');
            $targets = $tool === 'remove_tracks' ? $a['playlist_item_ids'] : [$a['playlist_item_id']];
            foreach ($targets as $target) {
                $label = ($tool === 'remove_tracks' ? 'remove:' : 'move:') . $target;
                if (isset($done[$label])) {
                    continue;
                }
                if (!isset($byId[$target])) {
                    throw new ServiceError(
                        'ITEM_NOT_IN_PLAYLIST',
                        'An entry is missing from this playlist. Refresh before retrying.',
                        [],
                        409
                    );
                }
                if ($tool === 'remove_tracks') {
                    $planned[] = [$label, 'playlistItems.delete', ['id' => $target], null];
                } else {
                    if ($a['position'] >= count($entries)) {
                        throw new ServiceError('INVALID_POSITION', 'Position is outside the playlist.');
                    }
                    if ($byId[$target]['snippet']['position'] === $a['position']) {
                        continue;
                    }
                    $planned[] = [
                        $label,
                        'playlistItems.update',
                        ['part' => 'snippet'],
                        [
                            'id' => $target,
                            'snippet' => [
                                'playlistId' => $id,
                                'position' => $a['position'],
                                'resourceId' => $byId[$target]['snippet']['resourceId']
                            ]
                        ]
                    ];
                }
            }
        }
        $planned = array_values(array_filter($planned, fn($p) => !array_key_exists($p[0], $done)));
        if (!$planned) {
            return ['playlist_id' => $id, 'changed' => 0, 'links' => $id ? $this->links($id) : []];
        }
        $cost = array_sum(array_map(fn($p) => $this->budget->method($p[1])[1], $planned));
        $reservation = $this->budget->reserve($user, $cost);
        $deadline = microtime(true) + 45;
        try {
            foreach ($planned as [$label, $method, $params, $body]) {
                if (microtime(true) > $deadline) {
                    throw new ServiceError(
                        'OPERATION_TIME_LIMIT',
                        'Batch time limit reached. Inspect this receipt and explicitly resume pending work.',
                        [],
                        409
                    );
                }
                $r = $step($label, fn() => $this->api->call($user, $method, $params, $body, $reservation, $op));
                if ($tool === 'create_playlist') {
                    $id = $r['id'];
                }
            }
        } finally {
            $this->budget->release($reservation);
        }
        return ['playlist_id' => $id, 'changed' => count($planned), 'links' => $id ? $this->links($id) : []];
    }

    private function metadata(string $user, string $id): array
    {
        $r = $this->api->call(
            $user,
            'playlists.list',
            ['part' => 'snippet,status,contentDetails', 'id' => $id],
            ttl: 0
        );
        return $r['items'][0] ?? throw new ServiceError(
            'PLAYLIST_NOT_FOUND',
            'Playlist not found or not accessible.',
            [],
            404
        );
    }

    private function mutatePreference(string $user, string $tool, array $a, callable $step, string $op, array $done): array
    {
        if (array_key_exists('change', $done)) {
            return ['changed' => 0];
        }
        $body = null;
        if ($tool === 'rate_track') {
            $target = $this->video($a['video_id']);
            if (!in_array($a['rating'], ['like', 'dislike', 'none'], true)) {
                throw new ServiceError('INVALID_RATING', 'Use like, dislike or none.');
            }
            $method = 'videos.rate';
            $params = ['id' => $target, 'rating' => $a['rating']];
        } else {
            $target = $a['channel_id'];
            if (!preg_match('/^UC[A-Za-z0-9_-]{22}$/', $target)) {
                throw new ServiceError('INVALID_CHANNEL', 'Supply a YouTube channel ID beginning with UC.');
            }
            // Resolve only subscriptions owned by the authenticated channel. Never accept an arbitrary subscription ID.
            $r = $this->api->call($user, 'subscriptions.list', [
                'part' => 'snippet', 'mine' => 'true', 'forChannelId' => $target, 'maxResults' => 50
            ]);
            $subscription = $r['items'][0]['id'] ?? null;
            if (($tool === 'subscribe_artist' && $subscription !== null) || ($tool === 'unsubscribe_artist' && $subscription === null)) {
                return ['channel_id' => $target, 'changed' => 0];
            }
            $method = $tool === 'subscribe_artist' ? 'subscriptions.insert' : 'subscriptions.delete';
            $params = $tool === 'subscribe_artist' ? ['part' => 'snippet'] : ['id' => $subscription];
            if ($tool === 'subscribe_artist') {
                $body = ['snippet' => ['resourceId' => ['kind' => 'youtube#channel', 'channelId' => $target]]];
            }
        }
        $step('change', fn() => $this->api->call($user, $method, $params, $body, operation: $op));
        return ['target_id' => $target, 'changed' => 1];
    }

    private function video(string $input): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input)) {
            return $input;
        }
        $p = parse_url($input);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https') {
            throw new ServiceError('INVALID_VIDEO', 'Supply a video ID or HTTPS YouTube video URL.');
        }
        $host = strtolower($p['host'] ?? '');
        if ($host === 'youtu.be') {
            $id = trim($p['path'] ?? '', '/');
        } elseif (in_array(
                $host,
                ['youtube.com', 'www.youtube.com', 'music.youtube.com'],
                true
            ) && ($p['path'] ?? '') === '/watch') {
            parse_str($p['query'] ?? '', $q);
            $id = $q['v'] ?? '';
        } else {
            $id = '';
        }
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            throw new ServiceError('INVALID_VIDEO', 'Unsupported YouTube video URL.');
        }
        return $id;
    }

    private function entries(string $user, string $id): array
    {
        $items = [];
        $page = null;
        $deadline = microtime(true) + 45;
        for ($n = 0; $n < 20; $n++) {
            if (microtime(true) > $deadline) {
                throw new ServiceError(
                    'PLAYLIST_SCAN_LIMIT',
                    'Preflight reached its time limit. No new writes were dispatched.',
                    [],
                    409
                );
            }
            $p = ['part' => 'snippet,contentDetails', 'playlistId' => $id, 'maxResults' => 50];
            if ($page) {
                $p['pageToken'] = $page;
            }
            $r = $this->api->call($user, 'playlistItems.list', $p);
            $items = array_merge($items, $r['items'] ?? []);
            $page = $r['nextPageToken'] ?? null;
            if (!$page) {
                return $items;
            }
        }
        throw new ServiceError(
            'PLAYLIST_SCAN_LIMIT',
            'Playlist is too large for a bounded preflight scan. No new changes have been dispatched.'
        );
    }

    private function links(string $id): array
    {
        return [
            'youtube' => 'https://www.youtube.com/playlist?list=' . $id,
            'youtube_music' => 'https://music.youtube.com/playlist?list=' . $id
        ];
    }
}
