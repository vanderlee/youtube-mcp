<?php

declare(strict_types=1);

namespace YouTubeMcp\Mcp;

use YouTubeMcp\Playlist\WorkflowTools;
use YouTubeMcp\ServiceError;
use YouTubeMcp\YouTube\CreatorTools;
use YouTubeMcp\YouTube\Tools as YouTubeTools;

/** Shared routing and permission policy for tool handlers, metadata and receipts. */
final class ToolCatalog
{
    private const WRITES = [
        'rate_track', 'subscribe_artist', 'unsubscribe_artist',
        'create_playlist', 'update_playlist', 'add_tracks', 'remove_tracks', 'move_track', 'delete_playlist',
        ...YouTubeTools::WRITES,
        ...WorkflowTools::WRITES,
        ...CreatorTools::WRITES,
    ];

    public static function owner(string $tool): string
    {
        if (isset(CreatorTools::specs()[$tool])) {
            return 'creator';
        }
        if (isset(YouTubeTools::specs()[$tool])) {
            return 'youtube';
        }
        if (isset(WorkflowTools::specs()[$tool])) {
            return 'workflow';
        }

        return match ($tool) {
            'get_operation' => 'receipts',
            'list_liked_tracks', 'get_track_ratings', 'rate_track',
            'list_subscriptions', 'subscribe_artist', 'unsubscribe_artist',
            'get_connection_status', 'get_usage_status', 'list_playlists', 'get_playlist', 'search_tracks',
            'create_playlist', 'update_playlist', 'add_tracks', 'remove_tracks', 'move_track', 'delete_playlist' => 'playlist',
            default => throw new ServiceError('UNKNOWN_TOOL', 'Unknown tool.'),
        };
    }

    public static function isWrite(string $tool): bool
    {
        return in_array($tool, self::WRITES, true);
    }

    public static function scope(string $tool): string
    {
        $owner = self::owner($tool);
        if ($owner === 'creator') {
            return CreatorTools::scope($tool);
        }
        if ($owner === 'youtube') {
            return YouTubeTools::scope($tool);
        }

        $group = match ($tool) {
            'list_liked_tracks', 'get_track_ratings', 'rate_track' => 'likes',
            'list_subscriptions', 'subscribe_artist', 'unsubscribe_artist' => 'subscriptions',
            default => 'playlists',
        };

        return $group . (self::isWrite($tool) ? '.write' : '.read');
    }
}
