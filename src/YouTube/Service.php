<?php

declare(strict_types=1);

namespace YouTubeMcp\YouTube;

use YouTubeMcp\Data;
use YouTubeMcp\Auth\Google;
use YouTubeMcp\Mcp\Arguments;
use YouTubeMcp\Mcp\ToolCatalog;
use YouTubeMcp\Operations\Receipts;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Lock;

/**
 * Implements YouTube discovery, channel, subscription and comment tools.
 *
 * Uses Api with scope and argument checks, account locks and Receipts for mutations.
 */
final class Service
{
    public function __construct(
        private Google $google,
        private Api $api,
        private Receipts $receipts,
        private Lock $locks,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    public function execute(string $user, array $scopes, string $tool, array $arguments): array
    {
        if (!in_array(ToolCatalog::scope($tool), $scopes, true)) {
            throw new ServiceError('INSUFFICIENT_SERVICE_SCOPE', 'Authorize ' . ToolCatalog::scope($tool) . '.', [], 403);
        }
        [, $properties, $required] = Tools::specs()[$tool];
        Arguments::validate($arguments, $properties, $required);

        return $this->locks->run(
            'account:' . $user,
            function () use ($user, $tool, $arguments) {
                $connection = $this->google->connection($user);
                if (!ToolCatalog::isWrite($tool)) {
                    return $this->read($user, $connection, $tool, $arguments);
                }

                return $this->receipts->run(
                    $user,
                    $connection['generation'],
                    $tool,
                    $arguments,
                    fn($step, $operation, $done) => $this->write($user, $connection, $tool, $arguments, $step, $operation),
                );
            },
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function searchParameters(array $arguments): array
    {
        $map = [
            'channel_id' => 'channelId',
            'published_after' => 'publishedAfter',
            'published_before' => 'publishedBefore',
            'order' => 'order',
            'duration' => 'videoDuration',
            'category_id' => 'videoCategoryId',
        ];
        if (
            isset($arguments['published_after'], $arguments['published_before'])
            && strtotime(Data::string($arguments['published_after'])) >= strtotime(Data::string($arguments['published_before']))
        ) {
            throw new ServiceError('INVALID_ARGUMENT', 'published_after must be before published_before.');
        }

        $result = [];
        foreach ($map as $argument => $parameter) {
            if (isset($arguments[$argument])) {
                $result[$parameter] = $arguments[$argument];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array{channel_id: string, generation: string} $connection
     * @return array<string, mixed>
     */
    private function read(string $user, array $connection, string $tool, array $arguments): array
    {
        $page = ['maxResults' => $arguments['page_size'] ?? 25] + (isset($arguments['page_token']) ? ['pageToken' => $arguments['page_token']] : []);
        if ($tool === 'get_video_details') {
            return $this->videoDetails($user, $arguments);
        }
        if ($tool === 'get_channel') {
            if (isset($arguments['channel_id']) && isset($arguments['handle'])) {
                throw new ServiceError('INVALID_ARGUMENT', 'Use channel_id or handle, not both.');
            }

            $filter = isset($arguments['channel_id']) ? ['id' => $arguments['channel_id']] : (isset($arguments['handle']) ? ['forHandle' => $arguments['handle']] : ['id' => $connection['channel_id']]);

            return $this->api->read($user, 'channels.list', ['part' => 'snippet,statistics,contentDetails'] + $filter);
        }
        if ($tool === 'list_channel_uploads') {
            return $this->channelUploads($user, $connection, $arguments, $page);
        }
        if ($tool === 'search_channels' || $tool === 'search_playlists') {
            return $this->api->read(
                $user,
                'search.list',
                [
                    'part' => 'snippet',
                    'type' => $tool === 'search_channels' ? 'channel' : 'playlist',
                    'q' => $arguments['query'],
                ]
                    + $page + self::searchParameters($arguments),
            );
        }
        if ($tool === 'list_comments') {
            return $this->api->read(
                $user,
                'commentThreads.list',
                [
                    'part' => 'snippet,replies',
                    'videoId' => $arguments['video_id'],
                    'textFormat' => 'plainText',
                    'order' => $arguments['order'] ?? 'time',
                ] + $page,
            )
                + ['embedded_replies_may_be_incomplete' => true];
        }

        return $this->api->read(
            $user,
            'comments.list',
            ['part' => 'snippet', 'parentId' => $arguments['comment_id'], 'textFormat' => 'plainText'] + $page,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array{channel_id: string, generation: string} $connection
     * @return array<string, mixed>
     */
    private function write(
        string $user,
        array $connection,
        string $tool,
        array $arguments,
        callable $step,
        string $operation,
    ): array {
        [$method, $params, $body] = $this->commentRequest($user, $connection, $tool, $arguments);

        $result = $step('comment-change', fn() => $this->api->call($user, $method, $params, $body, operation: $operation));
        if (
            in_array($tool, ['post_comment', 'reply_to_comment'], true)
            && (!is_string($result['id'] ?? null)
                || $result['id'] === '')
        ) {
            throw new ServiceError(
                'UPSTREAM_UNCERTAIN',
                'Google did not return the created comment ID. Reconcile before retrying.',
                [],
                502,
            );
        }

        return ['changed' => true, 'comment_id' => $result['id'] ?? $arguments['comment_id'] ?? null];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array{channel_id: string, generation: string} $connection
     * @return array{string, array<string, mixed>, array<string, mixed>|null}
     */
    private function commentRequest(string $user, array $connection, string $tool, array $arguments): array
    {
        $method = '';
        $params = ['part' => 'snippet'];
        $body = null;
        if ($tool === 'post_comment') {
            $method = 'commentThreads.insert';
            $body = [
                'snippet' => [
                    'videoId' => $arguments['video_id'],
                    'topLevelComment' => ['snippet' => ['textOriginal' => $arguments['text']]],
                ],
            ];
        } elseif ($tool === 'reply_to_comment') {
            $method = 'comments.insert';
            $body = ['snippet' => ['parentId' => $arguments['comment_id'], 'textOriginal' => $arguments['text']]];
        } else {
            $ids = Data::strings($arguments['comment_ids'] ?? [$arguments['comment_id']]);
            $comments = $this->api->call($user, 'comments.list', ['part' => 'snippet', 'id' => implode(',', $ids), 'maxResults' => 50]);
            $byId = array_column(Data::objects($comments['items'] ?? []), null, 'id');
            foreach ($ids as $id) {
                $comment = $byId[$id] ?? throw new ServiceError('COMMENT_NOT_FOUND', 'A comment was not returned.', [], 404);
                if (
                    $tool !== 'moderate_comments'
                    && (Data::get($comment, 'snippet', 'authorChannelId', 'value') ?? '') !== $connection['channel_id']
                ) {
                    throw new ServiceError('NOT_COMMENT_AUTHOR', 'Only comments authored by your confirmed channel may be changed.', [], 403);
                }
            }
            if ($tool === 'moderate_comments') {
                $this->requireOwnedCommentVideos($user, $connection['channel_id'], $comments, $byId, $ids);

                $method = 'comments.setModerationStatus';
                $params = ['id' => implode(',', $ids), 'moderationStatus' => $arguments['status']];
            } elseif ($tool === 'delete_comment') {
                $method = 'comments.delete';
                $params = ['id' => $arguments['comment_id']];
            } else {
                $method = 'comments.update';
                $body = ['id' => $arguments['comment_id'], 'snippet' => ['textOriginal' => $arguments['text']]];
            }
        }

        return [$method, $params, $body];
    }

    /**
     * @param array<string, array<string, mixed>> $byId
     * @param array<string, mixed> $comments
     * @param list<string> $ids
     */
    private function requireOwnedCommentVideos(
        string $user,
        string $channel,
        array $comments,
        array $byId,
        array $ids,
    ): void {
        $videos = array_unique(array_column(array_column(Data::array($comments['items']), 'snippet'), 'videoId'));
        if (count($videos) === 0) {
            throw new ServiceError('NOT_VIDEO_OWNER', 'Cannot verify the comments video ownership.', [], 403);
        }

        $response = $this->api->call($user, 'videos.list', ['part' => 'snippet', 'id' => implode(',', $videos)]);
        $owners = array_column(Data::array($response['items'] ?? []), 'snippet', 'id');
        foreach ($ids as $id) {
            $video = Data::get($byId, $id, 'snippet', 'videoId') ?? '';
            if (($owners[Data::string($video)]['channelId'] ?? '') !== $channel) {
                throw new ServiceError(
                    'NOT_VIDEO_OWNER',
                    'Only comments on videos owned by your confirmed channel may be moderated.',
                    [],
                    403,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function videoDetails(string $user, array $arguments): array
    {
        $response = $this->api->read(
            $user,
            'videos.list',
            [
                'part' => 'snippet,contentDetails,statistics,status',
                'id' => implode(',', Data::strings($arguments['video_ids'])),
            ],
        );

        return [
            'items' => $response['items'] ?? [],
            'not_returned_video_ids' => array_values(array_diff(Data::strings($arguments['video_ids']), array_column(Data::array($response['items'] ?? []), 'id'))),
            'availability_note' => 'Omitted videos may be private, deleted or inaccessible; metadata alone does not prove playback availability.',
        ];
    }

    /**
     * @param array{channel_id: string, generation: string} $connection
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function channelUploads(
        string $user,
        array $connection,
        array $arguments,
        array $page,
    ): array {
        $channel = $this->api->read(
            $user,
            'channels.list',
            ['part' => 'contentDetails', 'id' => $arguments['channel_id'] ?? $connection['channel_id']],
        );
        $uploads = Data::get($channel, 'items', 0, 'contentDetails', 'relatedPlaylists', 'uploads');
        if (!is_string($uploads) || $uploads === '') {
            throw new ServiceError('CHANNEL_NOT_FOUND', 'No accessible uploads playlist was returned.', [], 404);
        }

        return $this->api->read(
            $user,
            'playlistItems.list',
            ['part' => 'snippet,contentDetails', 'playlistId' => $uploads] + $page,
        );
    }
}
