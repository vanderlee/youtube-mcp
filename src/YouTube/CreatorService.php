<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\YouTube;

use YouTubeMusicMcp\Auth\Google;
use YouTubeMusicMcp\Mcp\Arguments;
use YouTubeMusicMcp\Operations\Receipts;
use YouTubeMusicMcp\ServiceError;
use YouTubeMusicMcp\Storage\Lock;

final class CreatorService
{
    public function __construct(private Google $google, private Api $api, private Receipts $receipts, private Lock $locks,
        private MediaStore $assets, private MediaApi $media) {}

    public function execute(string $user, array $scopes, string $tool, array $a): array
    {
        $scope = CreatorTools::scope($tool);
        if (!in_array($scope, $scopes, true)) { throw new ServiceError('INSUFFICIENT_SERVICE_SCOPE', 'Authorize ' . $scope . '.', [], 403); }
        [, $properties, $required] = CreatorTools::specs()[$tool];
        Arguments::validate($a, $properties, $required);
        return $this->locks->run('account:' . $user, function () use ($user, $tool, $a) {
            $connection = $this->google->connection($user);
            if (!in_array($tool, CreatorTools::WRITES, true)) { return $this->read($user, $connection, $tool, $a); }
            return $this->receipts->run($user, $connection['generation'], $tool, $a,
                fn($step, $operation, $done) => $this->write($user, $connection, $tool, $a, $step, $operation, $done));
        });
    }

    private function ownedVideo(string $user, array $connection, string $video): array
    {
        $response = $this->api->call($user, 'videos.list', ['part' => 'snippet,status', 'id' => $video]);
        $metadata = $response['items'][0] ?? null;
        if (!$metadata || ($metadata['id'] ?? '') !== $video || ($metadata['snippet']['channelId'] ?? '') !== $connection['channel_id']) {
            throw new ServiceError('NOT_VIDEO_OWNER', 'This video must be owned by your confirmed channel.', [], 403);
        }
        return $metadata;
    }

    private function caption(string $user, array $connection, array $a): array
    {
        $this->ownedVideo($user, $connection, $a['video_id']);
        $response = $this->api->call($user, 'captions.list', ['part' => 'snippet', 'videoId' => $a['video_id'], 'id' => $a['caption_id']]);
        foreach ($response['items'] ?? [] as $caption) {
            if ($caption['id'] === $a['caption_id'] && ($caption['snippet']['videoId'] ?? '') === $a['video_id']) { return $caption; }
        }
        throw new ServiceError('CAPTION_NOT_FOUND', 'Caption does not belong to this owned video.', [], 404);
    }

    private function read(string $user, array $connection, string $tool, array $a): array
    {
        if ($tool === 'list_media_assets') {
            $assets = $this->assets->listing($user, $connection);
            return ['items' => array_slice($assets, 0, 50), 'more_available' => count($assets) > 50];
        }
        if ($tool === 'list_video_categories') { return $this->api->call($user, 'videoCategories.list', ['part' => 'snippet', 'regionCode' => $a['region'] ?? 'US']); }
        if ($tool === 'list_channel_sections') { return $this->api->call($user, 'channelSections.list', ['part' => 'snippet,contentDetails', 'channelId' => $connection['channel_id']]); }
        if ($tool === 'download_caption') {
            $this->caption($user, $connection, $a);
            return $this->media->caption($user, $a['caption_id'], $a['format'] ?? 'vtt');
        }
        $this->ownedVideo($user, $connection, $a['video_id']);
        return $this->api->call($user, 'captions.list', ['part' => 'snippet', 'videoId' => $a['video_id']]);
    }

    private function write(string $user, array $connection, string $tool, array $a, callable $step, string $operation, array $done): array
    {
        if ($tool === 'delete_media_asset') {
            $step('delete-asset', function () use ($user, $connection, $a) { $this->assets->delete($user, $connection, $a['asset_id']); return ['deleted' => true]; });
            return ['asset_id' => $a['asset_id'], 'deleted' => true];
        }
        $video = null;
        if (isset($a['video_id']) && !isset($a['caption_id'])) { $video = $this->ownedVideo($user, $connection, $a['video_id']); }
        $asset = isset($a['asset_id']) ? $this->assets->get($user, $connection, $a['asset_id']) : null;
        if ($tool === 'upload_video') {
            $this->assertMime($asset, 'video/');
            $current = $this->api->call($user, 'channels.list', ['part' => 'id', 'mine' => 'true']);
            if (count($current['items'] ?? []) !== 1 || ($current['items'][0]['id'] ?? '') !== $connection['channel_id']) { throw new ServiceError('GOOGLE_ACCOUNT_MISMATCH', 'Google upload identity differs from your confirmed channel.', [], 403); }
            $result = $this->media->upload($user, 'videos.insert', ['part' => 'snippet,status'],
                ['snippet' => ['title' => $a['title'], 'description' => $a['description'] ?? '', 'categoryId' => $a['category_id'] ?? '22', 'tags' => $a['tags'] ?? []],
                    'status' => ['privacyStatus' => $a['privacy'] ?? 'private']], $asset, $step);
            if (!is_string($result['id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{11}$/D', $result['id'])) { throw new ServiceError('UPSTREAM_UNCERTAIN', 'Upload returned no valid video ID.', [], 502); }
            return ['video_id' => $result['id']];
        }
        if ($tool === 'set_video_thumbnail') {
            $this->assertMime($asset, 'image/');
            $this->media->upload($user, 'thumbnails.set', ['videoId' => $a['video_id']], [], $asset, $step);
            return ['video_id' => $a['video_id'], 'changed' => true];
        }
        if ($tool === 'update_video' || $tool === 'delete_video') {
            return $this->videoChange($user, $tool, $a, $video, $step, $operation);
        }
        if (str_contains($tool, 'caption')) {
            return $this->captionChange($user, $connection, $tool, $a, $asset, $step, $operation);
        }
        if ($tool === 'update_channel_branding' || $tool === 'set_channel_banner') {
            return $this->brandingChange($user, $connection, $tool, $a, $asset, $step, $operation);
        }
        return $this->sectionChange($user, $connection, $tool, $a, $step, $operation);
    }

    private function videoChange(string $user, string $tool, array $a, array $video, callable $step, string $operation): array
    {
        if ($tool === 'delete_video') { $step('video-change', fn() => $this->api->call($user, 'videos.delete', ['id' => $a['video_id']], operation: $operation)); }
        else {
            $body = ['id' => $a['video_id']];
            $snippet = array_intersect_key($video['snippet'], array_flip(['title', 'description', 'categoryId', 'tags', 'defaultLanguage', 'defaultAudioLanguage']));
            $supplied = false;
            foreach (['title' => 'title', 'description' => 'description', 'category_id' => 'categoryId', 'tags' => 'tags'] as $arg => $field) {
                if (array_key_exists($arg, $a)) { $snippet[$field] = $a[$arg]; $supplied = true; }
            }
            if ($supplied) { $body['snippet'] = $snippet; }
            if (isset($a['privacy'])) {
                $body['status'] = array_intersect_key($video['status'] ?? [], array_flip(['privacyStatus', 'embeddable', 'license', 'publicStatsViewable', 'selfDeclaredMadeForKids', 'publishAt']));
                $body['status']['privacyStatus'] = $a['privacy'];
                if ($a['privacy'] !== 'private') { unset($body['status']['publishAt']); }
            }
            if (count($body) === 1) { throw new ServiceError('INVALID_ARGUMENT', 'Supply a video metadata or privacy change.'); }
            $step('video-change', fn() => $this->api->call($user, 'videos.update', ['part' => implode(',', array_diff(array_keys($body), ['id']))], $body, operation: $operation));
        }
        return ['video_id' => $a['video_id'], 'changed' => true];
    }

    private function captionChange(string $user, array $connection, string $tool, array $a, ?array $asset, callable $step, string $operation): array
    {
        $existing = isset($a['caption_id']) ? $this->caption($user, $connection, $a) : null;
        if ($tool === 'delete_caption') { $step('caption-change', fn() => $this->api->call($user, 'captions.delete', ['id' => $a['caption_id']], operation: $operation)); return ['deleted' => true]; }
        $snippet = $existing ? array_intersect_key($existing['snippet'], array_flip(['name', 'isDraft'])) : ['videoId' => $a['video_id'], 'language' => $a['language'], 'name' => $a['name'] ?? '', 'isDraft' => $a['is_draft'] ?? false];
        foreach (['name' => 'name', 'is_draft' => 'isDraft'] as $arg => $field) { if (array_key_exists($arg, $a)) { $snippet[$field] = $a[$arg]; } }
        $body = ['snippet' => $snippet] + (isset($a['caption_id']) ? ['id' => $a['caption_id']] : []);
        if ($asset !== null) {
            $this->assertMime($asset, 'text/');
            $result = $this->media->upload($user, $existing ? 'captions.update' : 'captions.insert', ['part' => 'snippet'], $body, $asset, $step);
        } else {
            if (!isset($a['name']) && !array_key_exists('is_draft', $a)) { throw new ServiceError('INVALID_ARGUMENT', 'Supply caption metadata or an asset replacement.'); }
            $result = $step('caption-change', fn() => $this->api->call($user, 'captions.update', ['part' => 'snippet'], $body, operation: $operation));
        }
        $id = $result['id'] ?? $a['caption_id'] ?? null;
        if (!is_string($id) || $id === '') { throw new ServiceError('UPSTREAM_UNCERTAIN', 'Caption upload returned no track ID.', [], 502); }
        return ['caption_id' => $id];
    }

    private function brandingChange(string $user, array $connection, string $tool, array $a, ?array $asset, callable $step, string $operation): array
    {
        $channel = $this->api->call($user, 'channels.list', ['part' => 'brandingSettings', 'id' => $connection['channel_id']])['items'][0] ?? null;
            if (!$channel || ($channel['id'] ?? '') !== $connection['channel_id']) { throw new ServiceError('CHANNEL_NOT_FOUND', 'Your confirmed channel was not returned.', [], 404); }
        $branding = $channel['brandingSettings'] ?? [];
        if ($tool === 'set_channel_banner') {
            $this->assertMime($asset, 'image/');
            $uploaded = $this->media->upload($user, 'channelBanners.insert', [], [], $asset, $step);
            $url = $uploaded['url'] ?? null;
            if (!is_string($url) || !str_starts_with($url, 'https://')) { throw new ServiceError('UPSTREAM_UNCERTAIN', 'Banner upload returned no HTTPS image URL.', [], 502); }
            $branding['image']['bannerExternalUrl'] = $url;
        } else {
            $changes = array_intersect_key($a, array_flip(['description', 'keywords', 'country', 'trailer_video_id']));
            if (!$changes) { throw new ServiceError('INVALID_ARGUMENT', 'Supply at least one branding change.'); }
            if (isset($a['trailer_video_id'])) { $this->ownedVideo($user, $connection, $a['trailer_video_id']); }
            foreach ($changes as $key => $value) { $branding['channel'][$key === 'trailer_video_id' ? 'unsubscribedTrailer' : $key] = $value; }
        }
        $step('branding-change', fn() => $this->api->call($user, 'channels.update', ['part' => 'brandingSettings'], ['id' => $connection['channel_id'], 'brandingSettings' => $branding], operation: $operation));
        return ['channel_id' => $connection['channel_id'], 'changed' => true];
    }

    private function sectionChange(string $user, array $connection, string $tool, array $a, callable $step, string $operation): array
    {
        $section = null;
        if (isset($a['section_id'])) {
            $section = $this->api->call($user, 'channelSections.list', ['part' => 'snippet,contentDetails', 'id' => $a['section_id']])['items'][0] ?? null;
            if (($section['id'] ?? '') !== $a['section_id'] || ($section['snippet']['channelId'] ?? '') !== $connection['channel_id']) { throw new ServiceError('NOT_CHANNEL_OWNER', 'Section must belong to your confirmed channel.', [], 403); }
        }
        if ($tool === 'delete_channel_section') {
            $step('section-change', fn() => $this->api->call($user, 'channelSections.delete', ['id' => $a['section_id']], operation: $operation));
            return ['deleted' => true];
        }
        $snippet = array_intersect_key($section['snippet'] ?? [], array_flip(['type', 'title', 'position']));
        foreach (['type', 'title', 'position'] as $field) { if (array_key_exists($field, $a)) { $snippet[$field] = $a[$field]; } }
        $details = $section['contentDetails'] ?? [];
        foreach (['playlist_ids' => 'playlists', 'channel_ids' => 'channels'] as $arg => $field) { if (isset($a[$arg])) { $details[$field] = $a[$arg]; } }
        $this->validateSection($snippet, $details, $connection['channel_id']);
        $body = ['snippet' => $snippet, 'contentDetails' => $details] + (isset($a['section_id']) ? ['id' => $a['section_id']] : []);
        $result = $step('section-change', fn() => $this->api->call($user, $section ? 'channelSections.update' : 'channelSections.insert', ['part' => 'snippet,contentDetails'], $body, operation: $operation));
        $id = $result['id'] ?? $a['section_id'] ?? null;
        if (!is_string($id) || $id === '') { throw new ServiceError('UPSTREAM_UNCERTAIN', 'Section creation returned no ID.', [], 502); }
        return ['section_id' => $id];
    }

    private function assertMime(?array $asset, string $prefix): void
    {
        if (!$asset || !str_starts_with($asset['mime'], $prefix)) { throw new ServiceError('INVALID_MEDIA', 'Asset type is not supported by this operation.'); }
    }

    private function validateSection(array $snippet, array $details, string $channel): void
    {
        $type = $snippet['type'] ?? '';
        $playlists = $details['playlists'] ?? [];
        $channels = $details['channels'] ?? [];
        if (($type === 'singlePlaylist' && (count($playlists) !== 1 || $channels))
            || ($type === 'multiplePlaylists' && (!$playlists || $channels || empty($snippet['title'])))
            || ($type === 'multipleChannels' && (!$channels || $playlists || empty($snippet['title']) || in_array($channel, $channels, true)))
            || !in_array($type, ['singlePlaylist', 'multiplePlaylists', 'multipleChannels'], true)) {
            throw new ServiceError('INVALID_ARGUMENT', 'Section type, title and playlist/channel references are inconsistent.');
        }
    }
}
