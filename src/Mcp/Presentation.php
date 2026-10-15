<?php

declare(strict_types=1);

namespace YouTubeMcp\Mcp;

use YouTubeMcp\Data;

/**
 * Shapes compact model-facing results without changing receipts or upstream data.
 *
 * Lets Tools reduce response size while preserving the full result for callers that request it.
 */
final class Presentation
{
    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function compact(string $tool, array $data): array
    {
        if (isset($data['operation_id'], $data['completed'])) {
            return self::receipt(Data::object($data));
        }
        if (in_array($tool, ['search_channels', 'search_playlists'], true)) {
            $id = $tool === 'search_channels' ? 'channelId' : 'playlistId';
            $field = $tool === 'search_channels' ? 'channel_id' : 'playlist_id';

            return [
                'items' => array_map(
                    fn($item) => [$field => Data::get($item, 'id', $id)] + [
                        'title' => Data::get($item, 'snippet', 'title') ?? '',
                        'channel_id' => Data::get($item, 'snippet', 'channelId'),
                        'channel' => Data::get($item, 'snippet', 'channelTitle') ?? '',
                    ],
                    Data::array($data['items'] ?? []),
                ),
                'next_page_token' => $data['nextPageToken'] ?? null,
            ];
        }
        if ($tool === 'get_playlist') {
            return [
                'playlist' => self::playlist(Data::object($data['playlist'])),
                'entries' => array_map(fn($item) => self::entry(Data::object($item)), Data::array($data['entries'])),
                'next_page_token' => $data['next_page_token'] ?? null,
                'links' => $data['links'],
            ];
        }
        if (
            in_array($tool, ['list_playlists', 'list_liked_tracks', 'list_subscriptions', 'get_track_ratings'], true)
        ) {
            $items = array_map(
                function ($item) use ($tool) {
                    return match ($tool) {
                        'list_playlists' => self::playlist(Data::object($item)),
                        'list_liked_tracks' => [
                            'video_id' => Data::get($item, 'id'),
                            'title' => Data::get($item, 'snippet', 'title') ?? '',
                            'channel' => Data::get($item, 'snippet', 'channelTitle') ?? '',
                            'duration' => Data::get($item, 'contentDetails', 'duration'),
                        ],
                        'list_subscriptions' => [
                            'subscription_id' => Data::get($item, 'id'),
                            'channel_id' => Data::get($item, 'snippet', 'resourceId', 'channelId'),
                            'title' => Data::get($item, 'snippet', 'title') ?? '',
                        ],
                        default => ['video_id' => Data::get($item, 'videoId'), 'rating' => Data::get($item, 'rating')],
                    };
                },
                Data::array($data['items'] ?? []),
            );

            return ['items' => $items, 'next_page_token' => $data['nextPageToken'] ?? null]
                + array_intersect_key($data, array_flip(['music_availability']));
        }
        // Compact remaining results by omitting thumbnails.
        return self::omitThumbnails($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function receipt(array $data): array
    {
        $result = array_intersect_key(
            $data,
            array_flip(['operation_id', 'tool', 'status', 'result', 'error', 'pending_step']),
        );
        $result['completed_steps'] = array_keys(Data::array($data['completed']));

        $entries = [];
        foreach (Data::array($data['completed']) as $step => $value) {
            if (!is_array($value) || !isset($value['id'])) {
                continue;
            }

            $entries[] = ['step' => $step, 'id' => $value['id']] + array_filter(
                [
                    'video_id' => Data::get($value, 'snippet', 'resourceId', 'videoId'),
                    'position' => Data::get($value, 'snippet', 'position'),
                ],
                fn($item) => $item !== null,
            );
        }
        if ($entries) {
            $result['entries'] = $entries;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function playlist(array $item): array
    {
        return [
            'playlist_id' => $item['id'],
            'title' => Data::get($item, 'snippet', 'title') ?? '',
            'privacy' => Data::get($item, 'status', 'privacyStatus'),
            'item_count' => Data::get($item, 'contentDetails', 'itemCount'),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function entry(array $item): array
    {
        return [
            'playlist_item_id' => $item['id'],
            'video_id' => Data::get($item, 'snippet', 'resourceId', 'videoId') ?? Data::get($item, 'contentDetails', 'videoId'),
            'title' => Data::get($item, 'snippet', 'title') ?? '',
            'position' => Data::get($item, 'snippet', 'position'),
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
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
