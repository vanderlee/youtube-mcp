<?php

declare(strict_types=1);

namespace YouTubeMcp\YouTube;

final class Tools
{
    public const WRITES = ['post_comment', 'reply_to_comment', 'update_comment', 'delete_comment', 'moderate_comments'];

    public static function searchFilters(): array
    {
        return ['channel_id' => ['type' => 'string', 'pattern' => '^UC[A-Za-z0-9_-]{22}$'],
            'published_after' => ['type' => 'string', 'format' => 'date-time'],
            'published_before' => ['type' => 'string', 'format' => 'date-time'],
            'order' => ['type' => 'string', 'enum' => ['date', 'rating', 'relevance', 'title', 'viewCount']],
            'duration' => ['type' => 'string', 'enum' => ['any', 'short', 'medium', 'long']],
            'category_id' => ['type' => 'string', 'pattern' => '^[0-9]{1,3}$']];
    }

    public static function specs(): array
    {
        $id = ['type' => 'string', 'minLength' => 1, 'maxLength' => 256, 'pattern' => '^[A-Za-z0-9_-]+$'];
        $video = ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{11}$'];
        $channel = ['type' => 'string', 'pattern' => '^UC[A-Za-z0-9_-]{22}$'];
        $page = ['page_token' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 512],
            'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]];
        $operationProperties = ['operation_key' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._-]{8,128}$'], 'resume' => ['type' => 'boolean']];
        $text = ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000];
        $search = ['query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200]] + $page
            + array_diff_key(self::searchFilters(), ['duration' => true, 'category_id' => true]);

        return [
            'get_video_details' => ['Read up to 50 videos, statistics, duration and returned availability restrictions; omitted IDs are reported separately.',
                ['video_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'uniqueItems' => true, 'items' => $video]], ['video_ids']],
            'get_channel' => ['Resolve channel_id or @handle; omit both to read your confirmed channel. Does not change account selection.',
                ['channel_id' => $channel, 'handle' => ['type' => 'string', 'pattern' => '^@[^\\s]{1,100}$']], []],
            'list_channel_uploads' => ['Read one page of a channel uploads playlist; omitting channel_id uses your confirmed channel.', ['channel_id' => $channel] + $page, []],
            'search_channels' => ['Search YouTube channels, one page at a time.', $search, ['query']],
            'search_playlists' => ['Search YouTube playlists, one page at a time.', $search, ['query']],
            'list_comments' => ['Read one page of top-level video comment threads. Embedded replies may be incomplete.',
                ['video_id' => $video, 'order' => ['type' => 'string', 'enum' => ['time', 'relevance']]] + $page, ['video_id']],
            'list_comment_replies' => ['Read one page of replies to a top-level comment.', ['comment_id' => $id] + $page, ['comment_id']],
            'post_comment' => ['Post a top-level comment as your connected channel.', ['video_id' => $video, 'text' => $text] + $operationProperties, ['video_id', 'text', 'operation_key']],
            'reply_to_comment' => ['Reply to a top-level comment as your connected channel.', ['comment_id' => $id, 'text' => $text] + $operationProperties, ['comment_id', 'text', 'operation_key']],
            'update_comment' => ['Edit a comment authored by your confirmed channel.', ['comment_id' => $id, 'text' => $text] + $operationProperties, ['comment_id', 'text', 'operation_key']],
            'delete_comment' => ['Delete a comment authored by your confirmed channel.', ['comment_id' => $id] + $operationProperties, ['comment_id', 'operation_key']],
            'moderate_comments' => ['Publish, hold or reject up to 20 comments on videos owned by your confirmed channel.',
                ['comment_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'uniqueItems' => true, 'items' => $id],
                    'status' => ['type' => 'string', 'enum' => ['published', 'heldForReview', 'rejected']]] + $operationProperties, ['comment_ids', 'status', 'operation_key']],
        ];
    }

    public static function scope(string $tool): string
    {
        if ($tool === 'moderate_comments') {
            return 'comments.moderate';
        }
        if (str_contains($tool, 'comment')) {
            return in_array($tool, self::WRITES, true) ? 'comments.write' : 'comments.read';
        }

        return in_array($tool, ['get_channel', 'list_channel_uploads', 'search_channels'], true) ? 'channels.read' : 'videos.read';
    }
}
