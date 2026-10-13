<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Music;

final class Tools
{
    public const WRITES = [
        'set_music_song_library_status',
        'set_music_playlist_library_status',
        'add_music_history_item',
        'remove_music_history_items'
    ];

    public static function specs(): array
    {
        $id = ['type' => 'string', 'minLength' => 1, 'maxLength' => 256, 'pattern' => '^[A-Za-z0-9_-]+$'];
        $video = ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{11}$'];
        $query = ['type' => 'string', 'minLength' => 1, 'maxLength' => 200];
        $params = ['type' => 'string', 'minLength' => 1, 'maxLength' => 4096];
        $limit = ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]];
        $token = ['type' => 'string', 'minLength' => 1, 'maxLength' => 16384];
        $op = [
            'operation_key' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._-]{8,128}$'],
            'resume' => ['type' => 'boolean']
        ];
        return [
            'get_music_account' => [
                'Show the Music account name and channel handle for the connected Google authorization.',
                [],
                []
            ],
            'get_music_artist' => [
                'Read Music artist metadata and releases. Use a browse ID from Music search.',
                ['browse_id' => $id],
                ['browse_id']
            ],
            'get_music_artist_albums' => [
                'Read the first response of artist albums/singles using browseId and params from the corresponding artist section navigation. No continuation support.',
                ['browse_id' => $id, 'params' => $params] + $limit,
                ['browse_id', 'params']
            ],
            'get_music_album' => [
                'Read Music album metadata and up to 50 first-response tracks, including library action tokens.',
                ['browse_id' => $id],
                ['browse_id']
            ],
            'get_music_song' => [
                'Read song metadata and playability; does not stream or play audio.',
                ['video_id' => $video],
                ['video_id']
            ],
            'get_music_lyrics' => [
                'Read lyrics using the lyrics browse ID from get_music_radio.',
                ['browse_id' => $id],
                ['browse_id']
            ],
            'get_music_song_credits' => [
                'Read song credits using creditsBrowseId from album tracks.',
                ['browse_id' => $id],
                ['browse_id']
            ],
            'search_music' => [
                'Search the YouTube Music catalog with typed filters. Returns a bounded prefix, without page tokens.',
                [
                    'query' => $query,
                    'filter' => ['type' => 'string', 'enum' => ['songs', 'videos', 'albums', 'artists', 'playlists']],
                    'ignore_spelling' => ['type' => 'boolean']
                ] + $limit,
                ['query']
            ],
            'get_music_search_suggestions' => [
                'Read Music search suggestions.',
                ['query' => $query] + $limit,
                ['query']
            ],
            'get_music_home' => [
                'Read personalized Music home recommendation sections.',
                ['sections' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]],
                []
            ],
            'get_music_related' => [
                'Read related Music content using the related browse ID from get_music_radio.',
                ['browse_id' => $id],
                ['browse_id']
            ],
            'get_music_radio' => [
                'Read a generated Music radio queue, including lyrics/related IDs and library action tokens. Does not start playback.',
                ['video_id' => $video] + $limit,
                ['video_id']
            ],
            'get_music_charts' => [
                'Read Music charts; ZZ means global.',
                ['country' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$']],
                []
            ],
            'get_music_mood_categories' => ['Read Music mood and genre categories and their params.', [], []],
            'get_music_mood_playlists' => [
                'Read mood/genre playlists using params from get_music_mood_categories.',
                ['params' => $params] + $limit,
                ['params']
            ],
            'get_music_library' => [
                'Read saved Music songs, albums, artists, playlists or subscriptions. A bounded prefix, not a complete export.',
                [
                    'kind' => [
                        'type' => 'string',
                        'enum' => ['songs', 'albums', 'artists', 'playlists', 'subscriptions']
                    ],
                    'order' => ['type' => 'string', 'enum' => ['a_to_z', 'z_to_a', 'recently_added']]
                ] + $limit,
                ['kind']
            ],
            'set_music_song_library_status' => [
                'Apply a song library add/remove token from feedbackTokens.add/remove in an album, radio queue or library response. Tokens expire after one hour and are account-bound.',
                ['feedback_token' => $token] + $op,
                ['feedback_token', 'operation_key']
            ],
            'set_music_playlist_library_status' => [
                'Save or unsave a Music playlist/album. For an album use its audioPlaylistId, not its browse ID.',
                ['playlist_id' => $id, 'in_library' => ['type' => 'boolean']] + $op,
                ['playlist_id', 'in_library', 'operation_key']
            ],
            'get_music_history' => [
                'Read recent Music listening history and account-bound removal tokens. This is the history supplied by Music, not an exhaustive export.',
                $limit,
                []
            ],
            'add_music_history_item' => [
                'Record a song in Music listening history without playing audio. Requires an explicit user request.',
                ['video_id' => $video] + $op,
                ['video_id', 'operation_key']
            ],
            'remove_music_history_items' => [
                'Remove specific history occurrences by feedbackToken returned from get_music_history. Music may reject brand-account history removal.',
                [
                    'feedback_tokens' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 20,
                        'uniqueItems' => true,
                        'items' => $token
                    ]
                ] + $op,
                ['feedback_tokens', 'operation_key']
            ],
        ];
    }

    public static function scope(string $tool): string
    {
        return match ($tool) {
            'get_music_account', 'get_music_library' => 'library.read',
            'set_music_song_library_status', 'set_music_playlist_library_status' => 'library.write',
            'get_music_history' => 'history.read',
            'add_music_history_item', 'remove_music_history_items' => 'history.write',
            'get_music_home', 'get_music_related', 'get_music_radio', 'get_music_charts', 'get_music_mood_categories', 'get_music_mood_playlists' => 'discovery.read',
            default => 'catalog.read',
        };
    }
}
