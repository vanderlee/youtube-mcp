<?php

declare(strict_types=1);

namespace YouTubeMcp\YouTube;

final class CreatorTools
{
    public const WRITES = ['upload_video', 'update_video', 'delete_video', 'set_video_thumbnail', 'upload_caption', 'update_caption',
        'delete_caption', 'update_channel_branding', 'set_channel_banner', 'create_channel_section', 'update_channel_section', 'delete_channel_section', 'delete_media_asset'];

    public static function specs(): array
    {
        $video = ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{11}$'];
        $id = ['type' => 'string', 'minLength' => 1, 'maxLength' => 256, 'pattern' => '^[A-Za-z0-9_-]+$'];
        $asset = ['type' => 'string', 'pattern' => '^[a-f0-9]{48}$'];
        $op = ['operation_key' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._-]{8,128}$'], 'resume' => ['type' => 'boolean']];
        $title = ['type' => 'string', 'minLength' => 1, 'maxLength' => 100];
        $description = ['type' => 'string', 'maxLength' => 5000];
        $privacy = ['type' => 'string', 'enum' => ['private', 'unlisted', 'public']];
        $metadata = ['title' => $title, 'description' => $description, 'category_id' => ['type' => 'string', 'pattern' => '^[0-9]{1,3}$'],
            'tags' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100]], 'privacy' => $privacy];
        $caption = ['video_id' => $video, 'caption_id' => $id];
        $section = ['type' => ['type' => 'string', 'enum' => ['singlePlaylist', 'multiplePlaylists', 'multipleChannels']],
            'title' => ['type' => 'string', 'maxLength' => 100], 'position' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9],
            'playlist_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'uniqueItems' => true, 'items' => $id],
            'channel_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'uniqueItems' => true, 'items' => ['type' => 'string', 'pattern' => '^UC[A-Za-z0-9_-]{22}$']]];
        return [
            'list_video_categories' => ['Read assignable YouTube video categories for a region.', ['region' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$']], []],
            'list_media_assets' => ['List up to 50 active, user-owned staged media asset IDs. Files are staged through the private dashboard and expire after 24 hours.', [], []],
            'delete_media_asset' => ['Delete a staged media asset owned by your current connection; does not delete any YouTube video.', ['asset_id' => $asset] + $op, ['asset_id', 'operation_key']],
            'upload_video' => ['Upload a staged video (max 64 MiB), private by default. No automatic retransmission after interruption. Google may enforce private-only uploads for unverified API projects.', ['asset_id' => $asset] + $metadata + $op, ['asset_id', 'title', 'operation_key']],
            'update_video' => ['Update only supplied metadata/privacy of a video owned by your confirmed channel.', ['video_id' => $video] + $metadata + $op, ['video_id', 'operation_key']],
            'delete_video' => ['Permanently delete a video owned by your confirmed channel when explicitly requested.', ['video_id' => $video] + $op, ['video_id', 'operation_key']],
            'set_video_thumbnail' => ['Set an owned video thumbnail from a staged PNG/JPEG asset (max 2 MiB).', ['video_id' => $video, 'asset_id' => $asset] + $op, ['video_id', 'asset_id', 'operation_key']],
            'list_captions' => ['List caption tracks for a video owned by your confirmed channel; this is not a public transcript service.', ['video_id' => $video], ['video_id']],
            'download_caption' => ['Download an owned video caption as SRT or VTT, bounded to 1 MiB.', $caption + ['format' => ['type' => 'string', 'enum' => ['srt', 'vtt']]], ['video_id', 'caption_id']],
            'upload_caption' => ['Upload a staged timed SRT/VTT caption for an owned video. Automatic caption synchronization is not used.',
                ['video_id' => $video, 'asset_id' => $asset, 'language' => ['type' => 'string', 'pattern' => '^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$'],
                    'name' => ['type' => 'string', 'maxLength' => 150], 'is_draft' => ['type' => 'boolean']] + $op, ['video_id', 'asset_id', 'language', 'operation_key']],
            'update_caption' => ['Change an owned caption track name/draft state, optionally replacing its timed contents using an asset.',
                $caption + ['asset_id' => $asset, 'name' => ['type' => 'string', 'maxLength' => 150], 'is_draft' => ['type' => 'boolean']] + $op, ['video_id', 'caption_id', 'operation_key']],
            'delete_caption' => ['Delete a caption track from a video owned by your confirmed channel.', $caption + $op, ['video_id', 'caption_id', 'operation_key']],
            'update_channel_branding' => ['Change supplied description, keywords, country or unsubscribed trailer of your confirmed channel, preserving other branding fields.',
                ['description' => ['type' => 'string', 'maxLength' => 1000], 'keywords' => ['type' => 'string', 'maxLength' => 500],
                    'country' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$'], 'trailer_video_id' => $video] + $op, ['operation_key']],
            'set_channel_banner' => ['Upload and apply a staged PNG/JPEG banner to your confirmed channel. Google enforces image dimensions.', ['asset_id' => $asset] + $op, ['asset_id', 'operation_key']],
            'list_channel_sections' => ['Read your confirmed channel presentation sections.', [], []],
            'create_channel_section' => ['Create a playlist/channel presentation section on your confirmed channel.', $section + $op, ['type', 'operation_key']],
            'update_channel_section' => ['Update only supplied properties of a section owned by your confirmed channel.', ['section_id' => $id] + $section + $op, ['section_id', 'operation_key']],
            'delete_channel_section' => ['Delete a presentation section owned by your confirmed channel.', ['section_id' => $id] + $op, ['section_id', 'operation_key']],
        ];
    }

    public static function scope(string $tool): string
    {
        if (str_contains($tool, 'media_asset')) { return $tool === 'list_media_assets' ? 'media.read' : 'media.write'; }
        if (str_contains($tool, 'caption')) { return in_array($tool, self::WRITES, true) ? 'captions.write' : 'captions.read'; }
        if ($tool === 'upload_video') { return 'videos.upload'; }
        if (str_contains($tool, 'channel_')) { return in_array($tool, self::WRITES, true) ? 'channels.write' : 'channels.read'; }
        return in_array($tool, self::WRITES, true) ? 'videos.write' : 'videos.read';
    }
}
