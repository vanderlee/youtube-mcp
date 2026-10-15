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
 * Implements creator tools for owned videos, captions and channel presentation.
 *
 * Coordinates ownership checks, Api, MediaApi and MediaStore with locks and mutation receipts.
 * @phpstan-import-type Step from \YouTubeMcp\Operations\Receipts
 */
final class CreatorService
{
    public function __construct(
        private Google $google,
        private Api $api,
        private Receipts $receipts,
        private Lock $locks,
        private MediaStore $assets,
        private MediaApi $media,
    ) {}

    /**
     * @param array<string, mixed> $a
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    public function execute(string $user, array $scopes, string $tool, array $a): array
    {
        $scope = ToolCatalog::scope($tool);
        if (!in_array($scope, $scopes, true)) {
            throw new ServiceError('INSUFFICIENT_SERVICE_SCOPE', 'Authorize ' . $scope . '.', [], 403);
        }
        [, $properties, $required] = CreatorTools::specs()[$tool];
        Arguments::validate($a, $properties, $required);

        return $this->locks->run(
            'account:' . $user,
            function () use ($user, $tool, $a) {
                $connection = $this->google->connection($user);
                if (!ToolCatalog::isWrite($tool)) {
                    return $this->read($user, $connection, $tool, $a);
                }

                return $this->receipts->run(
                    $user,
                    $connection['generation'],
                    $tool,
                    $a,
                    fn($step, $operation, $done) => $this->write($user, $connection, $tool, $a, $step, $operation, $done),
                );
            },
        );
    }

    /**
     * @param array{channel_id: string, generation: string} $connection
     * @return array<string, mixed>
     */
    private function ownedVideo(string $user, array $connection, string $video): array
    {
        $response = $this->api->call($user, 'videos.list', ['part' => 'snippet,status', 'id' => $video]);
        $metadata = Data::get($response, 'items', 0);
        if (
            !$metadata
            || (Data::get($metadata, 'id') ?? '') !== $video
            || (Data::get($metadata, 'snippet', 'channelId') ?? '') !== $connection['channel_id']
        ) {
            throw new ServiceError('NOT_VIDEO_OWNER', 'This video must be owned by your confirmed channel.', [], 403);
        }

        return Data::object($metadata);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array{channel_id: string, generation: string} $connection
     * @return array<string, mixed>
     */
    private function caption(string $user, array $connection, array $arguments): array
    {
        $this->ownedVideo($user, $connection, Data::string($arguments['video_id']));
        $response = $this->api->call(
            $user,
            'captions.list',
            ['part' => 'snippet', 'videoId' => $arguments['video_id'], 'id' => $arguments['caption_id']],
        );
        foreach (Data::array($response['items'] ?? []) as $caption) {
            if (
                Data::get($caption, 'id') === $arguments['caption_id']
                && (Data::get($caption, 'snippet', 'videoId') ?? '') === $arguments['video_id']
            ) {
                return Data::object($caption);
            }
        }
        throw new ServiceError('CAPTION_NOT_FOUND', 'Caption does not belong to this owned video.', [], 404);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array{channel_id: string, generation: string} $connection
     * @return array<string, mixed>
     */
    private function read(string $user, array $connection, string $tool, array $arguments): array
    {
        if ($tool === 'list_media_assets') {
            $assets = $this->assets->listing($user, $connection);

            return ['items' => array_slice($assets, 0, 50), 'more_available' => count($assets) > 50];
        }
        if ($tool === 'list_video_categories') {
            return $this->api->call($user, 'videoCategories.list', ['part' => 'snippet', 'regionCode' => $arguments['region'] ?? 'US']);
        }
        if ($tool === 'list_channel_sections') {
            return $this->api->call(
                $user,
                'channelSections.list',
                ['part' => 'snippet,contentDetails', 'channelId' => $connection['channel_id']],
            );
        }
        if ($tool === 'download_caption') {
            $this->caption($user, $connection, $arguments);

            return $this->media->caption($user, Data::string($arguments['caption_id']), Data::string($arguments['format'] ?? 'vtt'));
        }

        $this->ownedVideo($user, $connection, Data::string($arguments['video_id']));

        return $this->api->call($user, 'captions.list', ['part' => 'snippet', 'videoId' => $arguments['video_id']]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array{channel_id: string, generation: string} $connection
     * @param array<string, mixed> $done
     * @param Step $step
     * @return array<string, mixed>
     */
    private function write(
        string $user,
        array $connection,
        string $tool,
        array $arguments,
        callable $step,
        string $operation,
        array $done,
    ): array {
        if ($tool === 'delete_media_asset') {
            $step(
                'delete-asset',
                function () use ($user, $connection, $arguments) {
                    $this->assets->delete($user, $connection, Data::string($arguments['asset_id']));

                    return ['deleted' => true];
                },
            );

            return ['asset_id' => $arguments['asset_id'], 'deleted' => true];
        }

        $video = null;
        if (isset($arguments['video_id']) && !isset($arguments['caption_id'])) {
            $video = $this->ownedVideo($user, $connection, Data::string($arguments['video_id']));
        }

        $asset = isset($arguments['asset_id']) ? $this->assets->get($user, $connection, Data::string($arguments['asset_id'])) : null;
        if ($tool === 'upload_video') {
            return $this->uploadVideo($user, $connection, $arguments, $asset, $step);
        }
        if ($tool === 'set_video_thumbnail') {
            $this->assertMime($asset, 'image/');
            $this->media->upload($user, 'thumbnails.set', ['videoId' => $arguments['video_id']], [], $asset, $step);

            return ['video_id' => $arguments['video_id'], 'changed' => true];
        }
        if ($tool === 'update_video' || $tool === 'delete_video') {
            if ($video === null) {
                throw new ServiceError('INVALID_ARGUMENT', 'Supply a video ID for this operation.');
            }

            return $this->videoChange($user, $tool, $arguments, $video, $step, $operation);
        }
        if (str_contains($tool, 'caption')) {
            return $this->captionChange($user, $connection, $tool, $arguments, $asset, $step, $operation);
        }
        if ($tool === 'update_channel_branding' || $tool === 'set_channel_banner') {
            return $this->brandingChange($user, $connection, $tool, $arguments, $asset, $step, $operation);
        }

        return $this->sectionChange($user, $connection, $tool, $arguments, $step, $operation);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $video
     * @param Step $step
     * @return array<string, mixed>
     */
    private function videoChange(
        string $user,
        string $tool,
        array $arguments,
        array $video,
        callable $step,
        string $operation,
    ): array {
        if ($tool === 'delete_video') {
            $step(
                'video-change',
                fn() => $this->api->call($user, 'videos.delete', ['id' => $arguments['video_id']], operation: $operation),
            );
        } else {
            $body = $this->videoUpdateBody($arguments, $video);

            $step(
                'video-change',
                fn() => $this->api->call(
                    $user,
                    'videos.update',
                    ['part' => implode(',', array_diff(array_keys($body), ['id']))],
                    $body,
                    operation: $operation,
                ),
            );
        }

        return ['video_id' => $arguments['video_id'], 'changed' => true];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $video
     * @return array<string, mixed>
     */
    private function videoUpdateBody(array $arguments, array $video): array
    {
        $body = ['id' => $arguments['video_id']];
        $snippet = array_intersect_key(
            Data::array($video['snippet']),
            array_flip(['title', 'description', 'categoryId', 'tags', 'defaultLanguage', 'defaultAudioLanguage']),
        );
        $supplied = false;
        foreach ([
            'title' => 'title',
            'description' => 'description',
            'category_id' => 'categoryId',
            'tags' => 'tags',
        ] as $arg => $field) {
            if (array_key_exists($arg, $arguments)) {
                $snippet[$field] = $arguments[$arg];
                $supplied = true;
            }
        }
        if ($supplied) {
            $body['snippet'] = $snippet;
        }
        if (isset($arguments['privacy'])) {
            $body['status'] = array_intersect_key(
                Data::array($video['status'] ?? []),
                array_flip(
                    [
                        'privacyStatus',
                        'embeddable',
                        'license',
                        'publicStatsViewable',
                        'selfDeclaredMadeForKids',
                        'publishAt',
                    ],
                ),
            );
            $body['status']['privacyStatus'] = $arguments['privacy'];
            if ($arguments['privacy'] !== 'private') {
                unset($body['status']['publishAt']);
            }
        }
        if (count($body) === 1) {
            throw new ServiceError('INVALID_ARGUMENT', 'Supply a video metadata or privacy change.');
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $asset
     * @param array{channel_id: string, generation: string} $connection
     * @param Step $step
     * @return array<string, mixed>
     */
    private function captionChange(
        string $user,
        array $connection,
        string $tool,
        array $arguments,
        ?array $asset,
        callable $step,
        string $operation,
    ): array {
        $existing = isset($arguments['caption_id']) ? $this->caption($user, $connection, $arguments) : null;
        if ($tool === 'delete_caption') {
            $step(
                'caption-change',
                fn() => $this->api->call($user, 'captions.delete', ['id' => $arguments['caption_id']], operation: $operation),
            );

            return ['deleted' => true];
        }

        $body = $this->captionBody($existing, $arguments);
        if ($asset !== null) {
            $this->assertMime($asset, 'text/');
            $result = $this->media->upload(
                $user,
                $existing ? 'captions.update' : 'captions.insert',
                ['part' => 'snippet'],
                $body,
                $asset,
                $step,
            );
        } else {
            if (!isset($arguments['name']) && !array_key_exists('is_draft', $arguments)) {
                throw new ServiceError('INVALID_ARGUMENT', 'Supply caption metadata or an asset replacement.');
            }

            $result = $step(
                'caption-change',
                fn() => $this->api->call($user, 'captions.update', ['part' => 'snippet'], $body, operation: $operation),
            );
        }

        $id = Data::get($result, 'id') ?? $arguments['caption_id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new ServiceError('UPSTREAM_UNCERTAIN', 'Caption upload returned no track ID.', [], 502);
        }

        return ['caption_id' => $id];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $asset
     * @param array{channel_id: string, generation: string} $connection
     * @param Step $step
     * @return array<string, mixed>
     */
    private function brandingChange(
        string $user,
        array $connection,
        string $tool,
        array $arguments,
        ?array $asset,
        callable $step,
        string $operation,
    ): array {
        $channel = Data::get($this->api->call($user, 'channels.list', ['part' => 'brandingSettings', 'id' => $connection['channel_id']]), 'items', 0);
        if (!$channel || (Data::get($channel, 'id') ?? '') !== $connection['channel_id']) {
            throw new ServiceError('CHANNEL_NOT_FOUND', 'Your confirmed channel was not returned.', [], 404);
        }

        $branding = Data::array(Data::get($channel, 'brandingSettings') ?? []);
        if ($tool === 'set_channel_banner') {
            $this->assertMime($asset, 'image/');
            $uploaded = $this->media->upload($user, 'channelBanners.insert', [], [], $asset, $step);
            $url = $uploaded['url'] ?? null;
            if (!is_string($url) || !str_starts_with($url, 'https://')) {
                throw new ServiceError('UPSTREAM_UNCERTAIN', 'Banner upload returned no HTTPS image URL.', [], 502);
            }

            $branding['image'] = Data::array($branding['image'] ?? []);
            $branding['image']['bannerExternalUrl'] = $url;
        } else {
            $changes = array_intersect_key($arguments, array_flip(['description', 'keywords', 'country', 'trailer_video_id']));
            if (!$changes) {
                throw new ServiceError('INVALID_ARGUMENT', 'Supply at least one branding change.');
            }
            if (isset($arguments['trailer_video_id'])) {
                $this->ownedVideo($user, $connection, Data::string($arguments['trailer_video_id']));
            }

            $branding['channel'] = Data::array($branding['channel'] ?? []);
            foreach ($changes as $key => $value) {
                $branding['channel'][$key === 'trailer_video_id' ? 'unsubscribedTrailer' : $key] = $value;
            }
        }

        $step(
            'branding-change',
            fn() => $this->api->call(
                $user,
                'channels.update',
                ['part' => 'brandingSettings'],
                ['id' => $connection['channel_id'], 'brandingSettings' => $branding],
                operation: $operation,
            ),
        );

        return ['channel_id' => $connection['channel_id'], 'changed' => true];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array{channel_id: string, generation: string} $connection
     * @param Step $step
     * @return array<string, mixed>
     */
    private function sectionChange(
        string $user,
        array $connection,
        string $tool,
        array $arguments,
        callable $step,
        string $operation,
    ): array {
        $section = null;
        if (isset($arguments['section_id'])) {
            $section = Data::get($this->api->call(
                $user,
                'channelSections.list',
                ['part' => 'snippet,contentDetails', 'id' => $arguments['section_id']],
            ), 'items', 0);
            if (
                (Data::get($section, 'id') ?? '') !== $arguments['section_id']
                || (Data::get($section, 'snippet', 'channelId') ?? '') !== $connection['channel_id']
            ) {
                throw new ServiceError('NOT_CHANNEL_OWNER', 'Section must belong to your confirmed channel.', [], 403);
            }
        }
        if ($tool === 'delete_channel_section') {
            $step(
                'section-change',
                fn() => $this->api->call($user, 'channelSections.delete', ['id' => $arguments['section_id']], operation: $operation),
            );

            return ['deleted' => true];
        }

        $body = $this->sectionBody($section === null ? null : Data::object($section), $arguments, $connection['channel_id']);
        $result = $step(
            'section-change',
            fn() => $this->api->call(
                $user,
                $section ? 'channelSections.update' : 'channelSections.insert',
                ['part' => 'snippet,contentDetails'],
                $body,
                operation: $operation,
            ),
        );
        $id = Data::get($result, 'id') ?? $arguments['section_id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new ServiceError('UPSTREAM_UNCERTAIN', 'Section creation returned no ID.', [], 502);
        }

        return ['section_id' => $id];
    }

    /**
     * @param array<string, mixed>|null $asset
     * @phpstan-assert non-empty-array<string, mixed> $asset
     */
    private function assertMime(?array $asset, string $prefix): void
    {
        if (!$asset || !str_starts_with(Data::string($asset['mime']), $prefix)) {
            throw new ServiceError('INVALID_MEDIA', 'Asset type is not supported by this operation.');
        }
    }

    /**
     * @param array<string, mixed> $details
     * @param array<string, mixed> $snippet
     */
    private function validateSection(array $snippet, array $details, string $channel): void
    {
        $type = $snippet['type'] ?? '';
        $playlists = $details['playlists'] ?? [];
        $channels = $details['channels'] ?? [];
        $invalidSection = match ($type) {
            'singlePlaylist' => count(Data::array($playlists)) !== 1 || $channels,
            'multiplePlaylists' => !$playlists || $channels || empty($snippet['title']),
            'multipleChannels' => !$channels
                || $playlists
                || empty($snippet['title'])
                || in_array($channel, Data::array($channels), true),
            default => true,
        };
        if ($invalidSection) {
            throw new ServiceError('INVALID_ARGUMENT', 'Section type, title and playlist/channel references are inconsistent.');
        }
    }

    /**
     * @param array{channel_id: string, generation: string} $connection
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $asset
     * @param Step $step
     * @return array<string, mixed>
     */
    private function uploadVideo(
        string $user,
        array $connection,
        array $arguments,
        ?array $asset,
        callable $step,
    ): array {
        $this->assertMime($asset, 'video/');
        $current = $this->api->call($user, 'channels.list', ['part' => 'id', 'mine' => 'true']);
        if (
            count(Data::array($current['items'] ?? [])) !== 1
            || (Data::get($current, 'items', 0, 'id') ?? '') !== $connection['channel_id']
        ) {
            throw new ServiceError('GOOGLE_ACCOUNT_MISMATCH', 'Google upload identity differs from your confirmed channel.', [], 403);
        }

        $result = $this->media->upload(
            $user,
            'videos.insert',
            ['part' => 'snippet,status'],
            [
                'snippet' => [
                    'title' => $arguments['title'],
                    'description' => $arguments['description'] ?? '',
                    'categoryId' => $arguments['category_id'] ?? '22',
                    'tags' => $arguments['tags'] ?? [],
                ],
                'status' => ['privacyStatus' => $arguments['privacy'] ?? 'private'],
            ],
            $asset,
            $step,
        );
        if (!is_string($result['id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{11}$/D', $result['id'])) {
            throw new ServiceError('UPSTREAM_UNCERTAIN', 'Upload returned no valid video ID.', [], 502);
        }

        return ['video_id' => $result['id']];
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function captionBody(?array $existing, array $arguments): array
    {
        $snippet = $existing ? array_intersect_key(Data::array($existing['snippet']), array_flip(['name', 'isDraft'])) : [
            'videoId' => $arguments['video_id'],
            'language' => $arguments['language'],
            'name' => $arguments['name'] ?? '',
            'isDraft' => $arguments['is_draft'] ?? false,
        ];
        foreach (['name' => 'name', 'is_draft' => 'isDraft'] as $arg => $field) {
            if (array_key_exists($arg, $arguments)) {
                $snippet[$field] = $arguments[$arg];
            }
        }

        return ['snippet' => $snippet] + (isset($arguments['caption_id']) ? ['id' => $arguments['caption_id']] : []);
    }

    /**
     * @param array<string, mixed>|null $section
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function sectionBody(?array $section, array $arguments, string $channel): array
    {
        $snippet = array_intersect_key(Data::array($section['snippet'] ?? []), array_flip(['type', 'title', 'position']));
        foreach (['type', 'title', 'position'] as $field) {
            if (array_key_exists($field, $arguments)) {
                $snippet[$field] = $arguments[$field];
            }
        }

        $details = Data::array($section['contentDetails'] ?? []);
        foreach (['playlist_ids' => 'playlists', 'channel_ids' => 'channels'] as $arg => $field) {
            if (isset($arguments[$arg])) {
                $details[$field] = $arguments[$arg];
            }
        }

        $this->validateSection($snippet, Data::object($details), $channel);
        return ['snippet' => $snippet, 'contentDetails' => $details] + (isset($arguments['section_id']) ? ['id' => $arguments['section_id']] : []);
    }
}
