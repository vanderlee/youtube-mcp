<?php

declare(strict_types=1);

namespace YouTubeMcp\Playlist;

use YouTubeMcp\YouTube\Api;
use YouTubeMcp\ServiceError;

final class Scanner
{
    public function __construct(private Api $api)
    {
    }

    public function metadata(string $user, string $playlist): array
    {
        $response = $this->api->call($user, 'playlists.list', ['part' => 'snippet,status,contentDetails', 'id' => $playlist]);
        $metadata = $response['items'][0] ?? throw new ServiceError('PLAYLIST_NOT_FOUND', 'Playlist not accessible.', [], 404);
        if (($metadata['id'] ?? '') !== $playlist) {
            throw new ServiceError('YOUTUBE_RESPONSE_INVALID', 'Google returned a different playlist.', [], 502);
        }

        return $metadata;
    }

    public function owned(string $user, string $playlist, string $channel): array
    {
        $metadata = $this->metadata($user, $playlist);
        if (($metadata['snippet']['channelId'] ?? '') !== $channel) {
            throw new ServiceError('NOT_PLAYLIST_OWNER', 'Only playlists owned by your confirmed channel may be changed.', [], 403);
        }

        return $metadata;
    }

    /** Complete snapshot or error: never plan mutations from a truncated scan. */
    public function entries(string $user, string $playlist, float $deadline): array
    {
        $entries = [];
        $page = null;
        $seen = [];
        for ($n = 0; $n < 20; $n++) {
            if (microtime(true) > $deadline) {
                throw new ServiceError('PLAYLIST_SCAN_LIMIT', 'Full scan exceeded its 45-second time bound.', [], 409);
            }

            $response = $this->api->call(
                $user,
                'playlistItems.list',
                ['part' => 'snippet,contentDetails,status', 'playlistId' => $playlist, 'maxResults' => 50]
                    + ($page === null ? [] : ['pageToken' => $page])
            );
            foreach ($response['items'] ?? [] as $entry) {
                if (!is_string($entry['id'] ?? null) || isset($seen[$entry['id']])) {
                    throw new ServiceError('PLAYLIST_CHANGED', 'Playlist changed during scanning. Start a new operation.', [], 409);
                }

                $seen[$entry['id']] = true;
                $entries[] = [
                    'id' => $entry['id'],
                    'video_id' => $entry['contentDetails']['videoId'] ?? $entry['snippet']['resourceId']['videoId'] ?? null,
                    'title' => $entry['snippet']['title'] ?? '',
                    'added_at' => $entry['snippet']['publishedAt'] ?? null,
                    'position' => $entry['snippet']['position'] ?? count($entries),
                    'privacy' => $entry['status']['privacyStatus'] ?? null
                ];
            }
            if (count($entries) > 1000) {
                break;
            }

            $page = $response['nextPageToken'] ?? null;
            if ($page === null || $page === '') {
                return $entries;
            }
        }
        throw new ServiceError(
            'PLAYLIST_SCAN_LIMIT',
            'A complete scan is limited to 1000 entries per playlist; no new write was dispatched.',
            [],
            409
        );
    }
}
