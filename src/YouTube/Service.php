<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\YouTube;

use YouTubeMusicMcp\Auth\Google;
use YouTubeMusicMcp\Mcp\Arguments;
use YouTubeMusicMcp\Operations\Receipts;
use YouTubeMusicMcp\ServiceError;
use YouTubeMusicMcp\Storage\Lock;

final class Service
{
    public function __construct(private Google $google, private Api $api, private Receipts $receipts, private Lock $locks) {}

    public function execute(string $user, array $scopes, string $tool, array $arguments): array
    {
        if (!in_array(Tools::scope($tool), $scopes, true)) { throw new ServiceError('INSUFFICIENT_SERVICE_SCOPE', 'Authorize ' . Tools::scope($tool) . '.', [], 403); }
        [, $properties, $required] = Tools::specs()[$tool];
        Arguments::validate($arguments, $properties, $required);
        return $this->locks->run('account:' . $user, function () use ($user, $tool, $arguments) {
            $connection = $this->google->connection($user);
            if (!in_array($tool, Tools::WRITES, true)) { return $this->read($user, $connection, $tool, $arguments); }
            return $this->receipts->run($user, $connection['generation'], $tool, $arguments,
                fn($step, $operation, $done) => $this->write($user, $connection, $tool, $arguments, $step, $operation));
        });
    }

    public static function searchParameters(array $arguments): array
    {
        $map = ['channel_id' => 'channelId', 'published_after' => 'publishedAfter', 'published_before' => 'publishedBefore',
            'order' => 'order', 'duration' => 'videoDuration', 'category_id' => 'videoCategoryId'];
        if (isset($arguments['published_after'], $arguments['published_before'])
            && strtotime($arguments['published_after']) >= strtotime($arguments['published_before'])) {
            throw new ServiceError('INVALID_ARGUMENT', 'published_after must be before published_before.');
        }
        $result = [];
        foreach ($map as $argument => $parameter) { if (isset($arguments[$argument])) { $result[$parameter] = $arguments[$argument]; } }
        return $result;
    }

    private function read(string $user, array $connection, string $tool, array $a): array
    {
        $page = ['maxResults' => $a['page_size'] ?? 25] + (isset($a['page_token']) ? ['pageToken' => $a['page_token']] : []);
        if ($tool === 'get_video_details') {
            $response = $this->api->call($user, 'videos.list', ['part' => 'snippet,contentDetails,statistics,status', 'id' => implode(',', $a['video_ids'])]);
            return ['items' => $response['items'] ?? [], 'not_returned_video_ids' => array_values(array_diff($a['video_ids'], array_column($response['items'] ?? [], 'id'))),
                'availability_note' => 'Omitted videos may be private, deleted or inaccessible; metadata alone does not prove playback availability.'];
        }
        if ($tool === 'get_channel') {
            if (isset($a['channel_id']) && isset($a['handle'])) { throw new ServiceError('INVALID_ARGUMENT', 'Use channel_id or handle, not both.'); }
            $filter = isset($a['channel_id']) ? ['id' => $a['channel_id']] : (isset($a['handle']) ? ['forHandle' => $a['handle']] : ['id' => $connection['channel_id']]);
            return $this->api->call($user, 'channels.list', ['part' => 'snippet,statistics,contentDetails'] + $filter);
        }
        if ($tool === 'list_channel_uploads') {
            $channel = $this->api->call($user, 'channels.list', ['part' => 'contentDetails', 'id' => $a['channel_id'] ?? $connection['channel_id']]);
            $uploads = $channel['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;
            if (!is_string($uploads) || $uploads === '') { throw new ServiceError('CHANNEL_NOT_FOUND', 'No accessible uploads playlist was returned.', [], 404); }
            return $this->api->call($user, 'playlistItems.list', ['part' => 'snippet,contentDetails', 'playlistId' => $uploads] + $page);
        }
        if ($tool === 'search_channels' || $tool === 'search_playlists') {
            return $this->api->call($user, 'search.list', ['part' => 'snippet', 'type' => $tool === 'search_channels' ? 'channel' : 'playlist', 'q' => $a['query']]
                + $page + self::searchParameters($a));
        }
        if ($tool === 'list_comments') {
            return $this->api->call($user, 'commentThreads.list', ['part' => 'snippet,replies', 'videoId' => $a['video_id'], 'textFormat' => 'plainText', 'order' => $a['order'] ?? 'time'] + $page)
                + ['embedded_replies_may_be_incomplete' => true];
        }
        return $this->api->call($user, 'comments.list', ['part' => 'snippet', 'parentId' => $a['comment_id'], 'textFormat' => 'plainText'] + $page);
    }

    private function write(string $user, array $connection, string $tool, array $a, callable $step, string $operation): array
    {
        $method = '';
        $params = ['part' => 'snippet'];
        $body = null;
        if ($tool === 'post_comment') {
            $method = 'commentThreads.insert';
            $body = ['snippet' => ['videoId' => $a['video_id'], 'topLevelComment' => ['snippet' => ['textOriginal' => $a['text']]]]];
        } elseif ($tool === 'reply_to_comment') {
            $method = 'comments.insert';
            $body = ['snippet' => ['parentId' => $a['comment_id'], 'textOriginal' => $a['text']]];
        } else {
            $ids = $a['comment_ids'] ?? [$a['comment_id']];
            $comments = $this->api->call($user, 'comments.list', ['part' => 'snippet', 'id' => implode(',', $ids), 'maxResults' => 50]);
            $byId = array_column($comments['items'] ?? [], null, 'id');
            foreach ($ids as $id) {
                $comment = $byId[$id] ?? throw new ServiceError('COMMENT_NOT_FOUND', 'A comment was not returned.', [], 404);
                if ($tool !== 'moderate_comments' && ($comment['snippet']['authorChannelId']['value'] ?? '') !== $connection['channel_id']) {
                    throw new ServiceError('NOT_COMMENT_AUTHOR', 'Only comments authored by your confirmed channel may be changed.', [], 403);
                }
            }
            if ($tool === 'moderate_comments') {
                $videos = array_unique(array_column(array_column($comments['items'], 'snippet'), 'videoId'));
                if (count($videos) === 0) { throw new ServiceError('NOT_VIDEO_OWNER', 'Cannot verify the comments video ownership.', [], 403); }
                $response = $this->api->call($user, 'videos.list', ['part' => 'snippet', 'id' => implode(',', $videos)]);
                $owners = array_column($response['items'] ?? [], 'snippet', 'id');
                foreach ($ids as $id) {
                    $video = $byId[$id]['snippet']['videoId'] ?? '';
                    if (($owners[$video]['channelId'] ?? '') !== $connection['channel_id']) { throw new ServiceError('NOT_VIDEO_OWNER', 'Only comments on videos owned by your confirmed channel may be moderated.', [], 403); }
                }
                $method = 'comments.setModerationStatus';
                $params = ['id' => implode(',', $ids), 'moderationStatus' => $a['status']];
            } elseif ($tool === 'delete_comment') {
                $method = 'comments.delete';
                $params = ['id' => $a['comment_id']];
            } else {
                $method = 'comments.update';
                $body = ['id' => $a['comment_id'], 'snippet' => ['textOriginal' => $a['text']]];
            }
        }
        $result = $step('comment-change', fn() => $this->api->call($user, $method, $params, $body, operation: $operation));
        if (in_array($tool, ['post_comment', 'reply_to_comment'], true) && (!is_string($result['id'] ?? null) || $result['id'] === '')) {
            throw new ServiceError('UPSTREAM_UNCERTAIN', 'Google did not return the created comment ID. Reconcile before retrying.', [], 502);
        }
        return ['changed' => true, 'comment_id' => $result['id'] ?? $a['comment_id'] ?? null];
    }
}
