<?php
declare(strict_types=1);
namespace YouTubeMcp\Music;

use YouTubeMcp\ServiceError;

/** Extract only named data fields; raw commands, credentials and tracking data stay upstream. */
final class Parser
{
    public static function text(mixed $value): string
    {
        if (is_string($value)) { return $value; }
        if (!is_array($value)) { return ''; }
        return $value['simpleText'] ?? implode('', array_column($value['runs'] ?? [], 'text'));
    }

    public static function find(array $node, string $key): array
    {
        $results = [];
        self::walk($node, static function ($name, $value) use ($key, &$results) {
            if ($name === $key && is_array($value)) { $results[] = $value; }
        });
        return $results;
    }

    public static function walk(array $node, callable $visit, int $depth = 0): void
    {
        if ($depth > 80) { throw new ServiceError('MUSIC_FORMAT_CHANGED', 'Music response nesting changed.', [], 502); }
        foreach ($node as $key => $value) {
            $visit($key, $value);
            if (is_array($value)) { self::walk($value, $visit, $depth + 1); }
        }
    }

    public static function first(array $node, string $key): ?array { return self::find($node, $key)[0] ?? null; }

    public static function items(array $node, int $limit = 50): array
    {
        $items = [];
        $visit = function (array $part, int $depth = 0) use (&$visit, &$items, $limit) {
            if ($depth > 80) { throw new ServiceError('MUSIC_FORMAT_CHANGED', 'Music response nesting changed.', [], 502); }
            foreach ($part as $key => $value) {
                if (count($items) >= $limit) { return; }
                if (!is_array($value)) { continue; }
                if (in_array($key, ['musicResponsiveListItemRenderer', 'musicTwoRowItemRenderer', 'playlistPanelVideoRenderer', 'musicCardShelfRenderer'], true)) {
                    $item = self::item($value);
                    if ($item['title'] !== '') { $items[] = $item; }
                    // Card shelves also contain additional result rows.
                    if ($key === 'musicCardShelfRenderer' && isset($value['contents'])) { $visit($value['contents'], $depth + 1); }
                } else { $visit($value, $depth + 1); }
            }
        };
        $visit($node);
        return $items;
    }

    public static function item(array $row): array
    {
        $columns = $row['flexColumns'] ?? [];
        $title = $row['title'] ?? $columns[0]['musicResponsiveListItemFlexColumnRenderer']['text'] ?? [];
        $subtitle = $row['subtitle'] ?? $row['longBylineText'] ?? $columns[1]['musicResponsiveListItemFlexColumnRenderer']['text'] ?? [];
        $primary = $row['navigationEndpoint'] ?? $row['onTap'] ?? $title['runs'][0]['navigationEndpoint'] ?? [];
        $watch = $primary['watchEndpoint'] ?? self::first($row['overlay'] ?? $row['thumbnailOverlay'] ?? [], 'watchEndpoint') ?? [];
        $browse = $primary['browseEndpoint'] ?? [];
        $video = $row['videoId'] ?? $row['playlistItemData']['videoId'] ?? $watch['videoId'] ?? null;
        $browseId = $browse['browseId'] ?? null;
        $pageType = $browse['browseEndpointContextSupportedConfigs']['browseEndpointContextMusicConfig']['pageType'] ?? '';
        $videoType = $watch['watchEndpointMusicSupportedConfigs']['watchEndpointMusicConfig']['musicVideoType'] ?? '';
        $type = match (true) {
            str_contains($pageType, 'ALBUM'), str_starts_with($browseId ?? '', 'MPRE') => 'album',
            str_contains($pageType, 'ARTIST'), str_starts_with($browseId ?? '', 'UC') => 'artist',
            str_contains($pageType, 'PLAYLIST'), str_starts_with($browseId ?? '', 'VL') => 'playlist',
            $videoType === 'MUSIC_VIDEO_TYPE_ATV' => 'song',
            $video !== null => 'video',
            default => 'unknown',
        };
        $item = ['title' => self::text($title), 'subtitle' => self::text($subtitle), 'resultType' => $type];
        foreach (['videoId' => $video, 'browseId' => $browseId, 'playlistId' => $watch['playlistId'] ?? (str_starts_with($browseId ?? '', 'VL') ? substr($browseId, 2) : null)] as $key => $value) {
            if (is_string($value) && $value !== '') { $item[$key] = $value; }
        }
        $metadata = [$subtitle];
        foreach (array_slice($columns, 2) as $column) { $metadata[] = $column['musicResponsiveListItemFlexColumnRenderer']['text'] ?? []; }
        $metadata[] = $row['shortBylineText'] ?? [];
        $artists = [];
        foreach ($metadata as $text) {
            foreach ($text['runs'] ?? [] as $run) {
                $id = $run['navigationEndpoint']['browseEndpoint']['browseId'] ?? null;
                if (is_string($id) && str_starts_with($id, 'UC')) { $artists[$id] = ['name' => $run['text'] ?? '', 'id' => $id]; }
                if (is_string($id) && str_starts_with($id, 'MPRE')) { $item['album'] = ['name' => $run['text'] ?? '', 'id' => $id]; }
                if (preg_match('/^\d{4}$/', $run['text'] ?? '')) { $item['year'] = $run['text']; }
                if (preg_match('/^(?:\d+:)+\d{2}$/', $run['text'] ?? '')) { $item['duration'] = $run['text']; }
            }
        }
        if ($artists) { $item['artists'] = array_values($artists); }
        $duration = self::text($row['lengthText'] ?? $row['fixedColumns'][0]['musicResponsiveListItemFixedColumnRenderer']['text'] ?? []);
        if ($duration !== '') { $item['duration'] = $duration; }
        $thumbs = self::first($row['thumbnail'] ?? $row['thumbnails'] ?? $row['thumbnailRenderer'] ?? [], 'thumbnails');
        if ($thumbs) { $item['thumbnails'] = array_slice($thumbs, 0, 5); }
        if (isset($row['musicItemRendererDisplayPolicy'])) { $item['isAvailable'] = $row['musicItemRendererDisplayPolicy'] !== 'MUSIC_ITEM_RENDERER_DISPLAY_POLICY_GREY_OUT'; }
        foreach (self::find($row['menu'] ?? [], 'menuToggleServiceItemRenderer') as $menu) {
            $icon = $menu['defaultIcon']['iconType'] ?? '';
            if (!in_array($icon, ['BOOKMARK_BORDER', 'BOOKMARK', 'LIBRARY_ADD', 'LIBRARY_REMOVE'], true)) { continue; }
            $adding = in_array($icon, ['BOOKMARK_BORDER', 'LIBRARY_ADD'], true);
            $default = self::first($menu['defaultServiceEndpoint'] ?? [], 'feedbackEndpoint')['feedbackToken'] ?? null;
            $toggled = self::first($menu['toggledServiceEndpoint'] ?? [], 'feedbackEndpoint')['feedbackToken'] ?? null;
            $item['inLibrary'] = !$adding || ($menu['isToggled'] ?? false);
            $item['feedbackTokens'] = array_filter(['add' => $adding ? $default : $toggled, 'remove' => $adding ? $toggled : $default], 'is_string');
        }
        foreach (self::find($row['menu'] ?? [], 'menuServiceItemRenderer') as $menu) {
            if (($menu['icon']['iconType'] ?? '') === 'REMOVE_FROM_HISTORY') {
                $token = self::first($menu['serviceEndpoint'] ?? [], 'feedbackEndpoint')['feedbackToken'] ?? null;
                if (is_string($token)) { $item['feedbackToken'] = $token; }
            }
        }
        foreach (self::find($row['menu'] ?? [], 'browseEndpoint') as $endpoint) {
            if (str_starts_with($endpoint['browseId'] ?? '', 'MPTC')) { $item['creditsBrowseId'] = $endpoint['browseId']; }
        }
        return $item;
    }

    public static function sections(array $data, int $limit = 10): array
    {
        $sections = [];
        $shelves = [];
        self::walk($data, static function ($key, $value) use (&$shelves) {
            if (in_array($key, ['musicCarouselShelfRenderer', 'musicShelfRenderer', 'musicPlaylistShelfRenderer', 'musicImmersiveCarouselShelfRenderer'], true)) { $shelves[] = $value; }
        });
        foreach ($shelves as $section) {
            $header = $section['header'] ?? [];
            $title = $section['title'] ?? self::first($header, 'title') ?? [];
            $items = self::items($section['contents'] ?? []);
            $entry = ['title' => self::text($title), 'contents' => $items];
            $endpoint = self::first($header ?: ($section['title'] ?? []), 'browseEndpoint') ?? self::first($section['bottomEndpoint'] ?? [], 'browseEndpoint');
            if ($endpoint) { $entry['navigation'] = array_intersect_key($endpoint, array_flip(['browseId', 'params'])); }
            if ($items || $entry['title'] !== '') { $sections[] = $entry; }
            if (count($sections) >= $limit) { return $sections; }
        }
        return $sections;
    }

    public static function recognized(array $data): void
    {
        if (!isset($data['contents']) && !isset($data['continuationContents'])) {
            throw new ServiceError('MUSIC_FORMAT_CHANGED', 'Music returned an unsupported response. Verify the account and client version.', [], 502);
        }
    }
}
