<?php

declare(strict_types=1);

namespace YouTubeMcp\Mcp;

/** Shape model-facing results without changing persisted receipts or upstream data. */
final class Presentation
{
    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function compact(string $tool, array $data): array
    {
        if (isset($data['operation_id'], $data['completed'])) {
            return self::receipt($data);
        }
        if ($tool === 'get_playlist') {
            return [
                'playlist' => self::playlist($data['playlist']),
                'entries' => array_map(fn($item) => self::entry($item), $data['entries']),
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
                        'list_playlists' => self::playlist($item),
                        'list_liked_tracks' => [
                            'video_id' => $item['id'],
                            'title' => $item['snippet']['title'] ?? '',
                            'channel' => $item['snippet']['channelTitle'] ?? '',
                            'duration' => $item['contentDetails']['duration'] ?? null,
                        ],
                        'list_subscriptions' => [
                            'subscription_id' => $item['id'],
                            'channel_id' => $item['snippet']['resourceId']['channelId'] ?? null,
                            'title' => $item['snippet']['title'] ?? '',
                        ],
                        default => ['video_id' => $item['videoId'], 'rating' => $item['rating']],
                    };
                },
                $data['items'] ?? [],
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
        $result['completed_steps'] = array_keys($data['completed']);

        $entries = [];
        foreach ($data['completed'] as $step => $value) {
            if (!is_array($value) || !isset($value['id'])) {
                continue;
            }

            $entries[] = ['step' => $step, 'id' => $value['id']] + array_filter(
                [
                    'video_id' => $value['snippet']['resourceId']['videoId'] ?? null,
                    'position' => $value['snippet']['position'] ?? null,
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
            'title' => $item['snippet']['title'] ?? '',
            'privacy' => $item['status']['privacyStatus'] ?? null,
            'item_count' => $item['contentDetails']['itemCount'] ?? null,
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
            'video_id' => $item['snippet']['resourceId']['videoId'] ?? $item['contentDetails']['videoId'] ?? null,
            'title' => $item['snippet']['title'] ?? '',
            'position' => $item['snippet']['position'] ?? null,
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
