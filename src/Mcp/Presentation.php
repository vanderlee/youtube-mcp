<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Mcp;

/** Shape model-facing results without changing persisted receipts or upstream data. */
final class Presentation
{
    public static function compact(string $tool, array $data): array
    {
        if (isset($data['operation_id'], $data['completed'])) {
            $result = array_intersect_key(
                $data,
                array_flip(['operation_id', 'tool', 'status', 'result', 'error', 'pending_step'])
            );
            $result['completed_steps'] = array_keys($data['completed']);
            $entries = [];
            foreach ($data['completed'] as $step => $value) {
                if (!is_array($value) || !isset($value['id'])) {
                    continue;
                }
                $entries[] = ['step' => $step, 'id' => $value['id']] + array_filter([
                        'video_id' => $value['snippet']['resourceId']['videoId'] ?? null,
                        'position' => $value['snippet']['position'] ?? null
                    ], fn($v) => $v !== null);
            }
            if ($entries) {
                $result['entries'] = $entries;
            }
            return $result;
        }
        if ($tool === 'get_playlist') {
            return [
                'playlist' => self::playlist($data['playlist']),
                'entries' => array_map(fn($v) => self::entry($v), $data['entries']),
                'next_page_token' => $data['next_page_token'] ?? null,
                'links' => $data['links']
            ];
        }
        if (in_array($tool, ['list_playlists', 'list_liked_tracks', 'list_subscriptions', 'get_track_ratings'], true)) {
            $items = array_map(function ($v) use ($tool) {
                return match ($tool) {
                    'list_playlists' => self::playlist($v),
                    'list_liked_tracks' => [
                        'video_id' => $v['id'],
                        'title' => $v['snippet']['title'] ?? '',
                        'channel' => $v['snippet']['channelTitle'] ?? '',
                        'duration' => $v['contentDetails']['duration'] ?? null
                    ],
                    'list_subscriptions' => [
                        'subscription_id' => $v['id'],
                        'channel_id' => $v['snippet']['resourceId']['channelId'] ?? null,
                        'title' => $v['snippet']['title'] ?? ''
                    ],
                    default => ['video_id' => $v['videoId'], 'rating' => $v['rating']]
                };
            }, $data['items'] ?? []);
            return ['items' => $items, 'next_page_token' => $data['nextPageToken'] ?? null] +
                array_intersect_key($data, array_flip(['music_availability']));
        }
        // Compact remaining results by omitting thumbnails.
        return self::omitThumbnails($data);
    }

    private static function playlist(array $v): array
    {
        return [
            'playlist_id' => $v['id'],
            'title' => $v['snippet']['title'] ?? '',
            'privacy' => $v['status']['privacyStatus'] ?? null,
            'item_count' => $v['contentDetails']['itemCount'] ?? null
        ];
    }

    private static function entry(array $v): array
    {
        return [
            'playlist_item_id' => $v['id'],
            'video_id' => $v['snippet']['resourceId']['videoId'] ?? $v['contentDetails']['videoId'] ?? null,
            'title' => $v['snippet']['title'] ?? '',
            'position' => $v['snippet']['position'] ?? null
        ];
    }

    private static function omitThumbnails(array $data): array
    {
        foreach ($data as $key => &$value) {
            if (in_array($key, ['thumbnails', 'thumbnail'], true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $value = self::omitThumbnails($value);
            }
        }
        unset($value);
        return $data;
    }
}
