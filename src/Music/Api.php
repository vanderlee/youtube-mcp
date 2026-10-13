<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Music;

use YouTubeMusicMcp\{Config, ServiceError};
use YouTubeMusicMcp\Http\Client;

/** Native PHP adapter to Music's unofficial WEB_REMIX endpoints. No subprocesses. */
class Api
{
    private Client $http;
    private Bootstrap $bootstrap;

    public function __construct(private Config $config, ?Client $http = null, ?Bootstrap $bootstrap = null)
    {
        $this->http = $http ?? new Transport();
        $this->bootstrap = $bootstrap ?? new Bootstrap($this->http);
    }

    public function call(string $tool, array $a, ?string $accessToken = null): mixed
    {
        if (!isset(Tools::specs()[$tool])) {
            throw new ServiceError('UNKNOWN_TOOL', 'Unknown Music tool.');
        }
        if (!$accessToken) {
            throw new ServiceError('GOOGLE_RECONNECT_REQUIRED', 'Connect Google first.', [], 401);
        }
        $limit = $a['limit'] ?? 25;
        if ($tool === 'set_music_song_library_status' || $tool === 'remove_music_history_items') {
            $tokens = $tool === 'set_music_song_library_status' ? [$a['feedback_token']] : $a['feedback_tokens'];
            $r = $this->request('feedback', ['feedbackTokens' => $tokens], $accessToken, true);
            $responses = $r['feedbackResponses'] ?? [];
            if (count($responses) !== count($tokens) || array_filter(
                    $responses,
                    fn($entry) => ($entry['isProcessed'] ?? false) !== true
                )) {
                throw new ServiceError(
                    'UPSTREAM_UNCERTAIN',
                    'Music did not confirm every feedback action. Inspect the account before retrying.',
                    [],
                    502
                );
            }
            return ['changed' => count($tokens)];
        }
        if ($tool === 'set_music_playlist_library_status') {
            $this->request(
                $a['in_library'] ? 'like/like' : 'like/removelike',
                ['target' => ['playlistId' => $a['playlist_id']]],
                $accessToken,
                true
            );
            return ['changed' => 1];
        }
        if ($tool === 'get_music_song' || $tool === 'add_music_history_item') {
            $r = $this->request('player', [
                'videoId' => $a['video_id'],
                'playbackContext' => [
                    'contentPlaybackContext' => [
                        'signatureTimestamp' => (int)floor(
                                time() / 86400
                            ) - 1
                    ]
                ]
            ], $accessToken);
            if (!isset($r['playabilityStatus'])) {
                $this->formatError();
            }
            if ($tool === 'get_music_song') {
                return array_intersect_key($r, array_flip(['videoDetails', 'playabilityStatus']));
            }
            $url = $r['playbackTracking']['videostatsPlaybackUrl']['baseUrl'] ?? '';
            $parts = is_string($url) ? parse_url($url) : false;
            if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !in_array(
                    $parts['host'] ?? '',
                    ['www.youtube.com', 'youtube.com', 'music.youtube.com'],
                    true
                )
                || ($parts['path'] ?? '') !== '/api/stats/playback' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
                throw new ServiceError(
                    'MUSIC_HISTORY_UNAVAILABLE',
                    'Music did not return a supported history endpoint.',
                    [],
                    502
                );
            }
            parse_str($parts['query'] ?? '', $params);
            $params = array_replace(
                $params,
                [
                    'ver' => 2,
                    'c' => 'WEB_REMIX',
                    'cpn' => rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=')
                ]
            );
            $trackingUrl = 'https://' . $parts['host'] . '/api/stats/playback?' . http_build_query($params);
            $response = $this->http->request('GET', $trackingUrl, $this->headers($accessToken));
            $this->checkStatus($response['status'], true, $response['data']);
            if ($response['status'] !== 204) {
                throw new ServiceError('UPSTREAM_UNCERTAIN', 'Music did not confirm the history addition.', [], 502);
            }
            return ['changed' => 1];
        }
        if ($tool === 'get_music_account') {
            $r = $this->request('account/account_menu', [], $accessToken);
            $header = Parser::first($r, 'activeAccountHeaderRenderer');
            if (!$header) {
                $this->formatError();
            }
            return [
                'accountName' => Parser::text($header['accountName'] ?? []),
                'channelHandle' => Parser::text($header['channelHandle'] ?? [])
            ];
        }
        if ($tool === 'search_music') {
            $body = ['query' => $a['query']];
            $params = $this->searchParams($a['filter'] ?? null, $a['ignore_spelling'] ?? false);
            if ($params !== null) {
                $body['params'] = $params;
            }
            $r = $this->request('search', $body, $accessToken);
            Parser::recognized($r);
            return $this->listing($r, $limit);
        }
        if ($tool === 'get_music_search_suggestions') {
            $r = $this->request('music/get_search_suggestions', ['input' => $a['query']], $accessToken);
            Parser::recognized($r);
            $items = [];
            foreach (['searchSuggestionRenderer', 'historySuggestionRenderer'] as $key) {
                foreach (Parser::find($r, $key) as $suggestion) {
                    $text = $suggestion['navigationEndpoint']['searchEndpoint']['query'] ?? Parser::text(
                        $suggestion['suggestion'] ?? []
                    );
                    if ($text !== '') {
                        $items[] = $text;
                    }
                }
            }
            return ['items' => array_slice($items, 0, $limit), 'possibly_more' => count($items) > $limit];
        }
        if ($tool === 'get_music_radio') {
            $r = $this->request('next', [
                'videoId' => $a['video_id'],
                'playlistId' => 'RDAMVM' . $a['video_id'],
                'params' => 'wAEB',
                'isAudioOnly' => true,
                'enablePersistentPlaylistPanel' => true,
                'tunerSettingValue' => 'AUTOMIX_SETTING_NORMAL'
            ], $accessToken);
            $panel = Parser::first($r, 'playlistPanelRenderer');
            if (!$panel) {
                $this->formatError();
            }
            $data = [
                'tracks' => Parser::items($panel, $limit),
                'playlistId' => $panel['playlistId'] ?? null,
                'possibly_more' => $this->hasContinuation($panel),
                'upstream_prefix_only' => true
            ];
            foreach (Parser::find($r, 'browseEndpoint') as $endpoint) {
                $type = $endpoint['browseEndpointContextSupportedConfigs']['browseEndpointContextMusicConfig']['pageType'] ?? '';
                if ($type === 'MUSIC_PAGE_TYPE_TRACK_LYRICS') {
                    $data['lyrics'] = $endpoint['browseId'];
                }
                if ($type === 'MUSIC_PAGE_TYPE_TRACK_RELATED') {
                    $data['related'] = $endpoint['browseId'];
                }
            }
            return $data;
        }
        $body = match ($tool) {
            'get_music_home' => ['browseId' => 'FEmusic_home'],
            'get_music_mood_categories' => ['browseId' => 'FEmusic_moods_and_genres'],
            'get_music_mood_playlists' => ['browseId' => 'FEmusic_moods_and_genres_category', 'params' => $a['params']],
            'get_music_charts' => [
                'browseId' => 'FEmusic_charts',
                'formData' => ['selectedValues' => [$a['country'] ?? 'ZZ']]
            ],
            'get_music_history' => ['browseId' => 'FEmusic_history'],
            'get_music_library' => [
                'browseId' => match ($a['kind']) {
                    'songs' => 'FEmusic_liked_videos',
                    'albums' => 'FEmusic_liked_albums',
                    'artists' => 'FEmusic_library_corpus_track_artists',
                    'playlists' => 'FEmusic_liked_playlists',
                    'subscriptions' => 'FEmusic_library_corpus_artists',
                }
            ],
            default => ['browseId' => $a['browse_id']],
        };
        if ($tool === 'get_music_artist_albums') {
            $body['params'] = $a['params'];
        }
        if ($tool === 'get_music_library' && isset($a['order'])) {
            $body['params'] = [
                'a_to_z' => 'ggMGKgQIARAA',
                'z_to_a' => 'ggMGKgQIARAB',
                'recently_added' => 'ggMGKgQIABAB'
            ][$a['order']];
        }
        $r = $this->request('browse', $body, $accessToken);
        if ($tool === 'get_music_lyrics') {
            $shelf = Parser::first($r, 'musicDescriptionShelfRenderer');
            if (!$shelf) {
                throw new ServiceError('MUSIC_LYRICS_UNAVAILABLE', 'Music did not supply lyrics for this item.', [], 404
                );
            }
            return [
                'lyrics' => Parser::text($shelf['description'] ?? []),
                'source' => Parser::text($shelf['footer'] ?? [])
            ];
        }
        if ($tool === 'get_music_song_credits') {
            $sections = Parser::find($r, 'dismissableDialogContentSectionRenderer');
            if (!$sections) {
                $this->formatError();
            }
            return [
                'sections' => array_map(
                    fn($s) => [
                        'title' => Parser::text($s['title'] ?? []),
                        'text' => Parser::text($s['subtitle'] ?? [])
                    ],
                    array_slice($sections, 0, 50)
                )
            ];
        }
        Parser::recognized($r);
        if ($tool === 'get_music_mood_categories') {
            $categories = [];
            foreach (Parser::find($r, 'musicNavigationButtonRenderer') as $button) {
                $endpoint = Parser::first($button, 'browseEndpoint');
                if (isset($endpoint['params'])) {
                    $categories[] = [
                        'title' => Parser::text($button['buttonText'] ?? []),
                        'params' => $endpoint['params']
                    ];
                }
            }
            return ['categories' => array_slice($categories, 0, 50), 'possibly_more' => count($categories) > 50];
        }
        if ($tool === 'get_music_history') {
            $items = [];
            foreach (Parser::find($r, 'musicShelfRenderer') as $shelf) {
                foreach (Parser::items($shelf) as $item) {
                    $item['played'] = Parser::text($shelf['title'] ?? []);
                    $items[] = $item;
                }
            }
            if (!$items && Parser::first($r, 'musicNotifierShelfRenderer')) {
                throw new ServiceError('MUSIC_HISTORY_UNAVAILABLE', 'Music history is unavailable or paused.', [], 409);
            }
            return [
                'items' => array_slice($items, 0, $limit),
                'possibly_more' => count($items) > $limit || $this->hasContinuation($r),
                'upstream_prefix_only' => true
            ];
        }
        if (in_array($tool, ['get_music_home', 'get_music_related', 'get_music_charts'], true)) {
            return ['sections' => Parser::sections($r, $a['sections'] ?? 10), 'upstream_prefix_only' => true];
        }
        if ($tool === 'get_music_artist' || $tool === 'get_music_album') {
            $header = Parser::first($r['header'] ?? [], 'musicImmersiveHeaderRenderer')
                ?? Parser::first($r['header'] ?? [], 'musicVisualHeaderRenderer')
                ?? Parser::first($r['header'] ?? [], 'musicDetailHeaderRenderer')
                ?? Parser::first($r, 'musicResponsiveHeaderRenderer');
            if (!$header) {
                $this->formatError();
            }
            $metadata = [
                'browseId' => $a['browse_id'],
                'title' => Parser::text($header['title'] ?? []),
                'subtitle' => Parser::text($header['subtitle'] ?? []),
                'description' => Parser::text($header['description'] ?? [])
            ];
            $parsedHeader = Parser::item($header);
            foreach (['artists', 'year', 'thumbnails'] as $field) {
                if (isset($parsedHeader[$field])) {
                    $metadata[$field] = $parsedHeader[$field];
                }
            }
            $subscribe = Parser::first($header, 'subscribeButtonRenderer');
            if ($subscribe) {
                $metadata['subscribed'] = $subscribe['subscribed'] ?? null;
                $metadata['subscribers'] = Parser::text($subscribe['subscriberCountText'] ?? []);
            }
            $metadata['sections'] = Parser::sections($r);
            if ($tool === 'get_music_album') {
                // Album tracks are in the secondary shelf; related album carousels are separate.
                $shelf = Parser::first($r, 'musicShelfRenderer') ?? Parser::first($r, 'musicPlaylistShelfRenderer');
                $metadata['tracks'] = $shelf ? Parser::items($shelf) : [];
                foreach ($metadata['tracks'] as &$track) {
                    if (!isset($track['artists']) && isset($metadata['artists'])) {
                        $track['artists'] = $metadata['artists'];
                    }
                    $track['album'] ??= ['name' => $metadata['title'], 'id' => $a['browse_id']];
                }
                unset($track);
                foreach (Parser::find($header, 'watchPlaylistEndpoint') as $endpoint) {
                    if (isset($endpoint['playlistId'])) {
                        $metadata['audioPlaylistId'] = $endpoint['playlistId'];
                        break;
                    }
                }
                if (!isset($metadata['audioPlaylistId'])) {
                    foreach (Parser::find($header, 'watchEndpoint') as $endpoint) {
                        if (isset($endpoint['playlistId']) && str_starts_with($endpoint['playlistId'], 'OLAK')) {
                            $metadata['audioPlaylistId'] = $endpoint['playlistId'];
                            break;
                        }
                    }
                }
            }
            $metadata['upstream_prefix_only'] = true;
            return $metadata;
        }
        return $this->listing($r, $limit);
    }

    private function request(string $endpoint, array $body, string $token, bool $write = false): array
    {
        $profile = $this->bootstrap->load($this->config);
        $client = [
            'clientName' => 'WEB_REMIX',
            'clientVersion' => $profile['clientVersion'],
            'hl' => 'en'
        ];
        $country = $this->config->get('MUSIC_LOCATION');
        if ($country !== '') {
            $client['gl'] = $country;
        }
        $body['context'] = ['client' => $client, 'user' => new \stdClass()];
        try {
            $r = $this->http->request(
                'POST',
                'https://music.youtube.com/youtubei/v1/' . $endpoint . '?alt=json',
                $this->headers($token, $profile),
                json_encode($body, JSON_THROW_ON_ERROR)
            );
        } catch (ServiceError $error) {
            if (!$write && $error->reason === 'UPSTREAM_UNCERTAIN') {
                throw new ServiceError('MUSIC_UNAVAILABLE', 'Music request did not complete.', [], 502);
            }
            throw $error;
        }
        try {
            $this->checkStatus($r['status'], $write, $r['data'], $profile);
        } catch (ServiceError $error) {
            Diagnostics::log($error, $endpoint);
            throw $error;
        }
        $data = $r['data'];
        if (isset($data['error'])) {
            throw new ServiceError('MUSIC_UPSTREAM_REJECTED', 'Music rejected the request.',
                ['upstream_http_status' => $r['status']] + (Transport::safeError($data)['error'] ?? []), 502);
        }
        if ($write && Parser::first($data, 'showEngagementPanelEndpoint')) {
            throw new ServiceError(
                'MUSIC_INTERACTION_REQUIRED',
                'Music requires an account interaction in its website before this change.',
                [],
                409
            );
        }
        return $data;
    }

    private function headers(string $token, ?array $profile = null): array
    {
        $profile ??= $this->bootstrap->load($this->config);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: */*',
            'Origin: https://music.youtube.com',
            'Referer: https://music.youtube.com/',
            'Cookie: SOCS=CAI',
            'X-Goog-Request-Time: ' . time(),
            'User-Agent: ' . Bootstrap::USER_AGENT
        ];
        if ($profile !== null) {
            // OAuth requests follow the reference profile: client identity belongs in
            // context.client; the public visitor identifier is a separate header.
            $headers[] = 'X-Goog-Visitor-Id: ' . $profile['visitorData'];
        }
        return $headers;
    }

    private function checkStatus(int $status, bool $write, array $data = [], ?array $profile = null): void
    {
        $safe = Transport::safeError($data)['error'] ?? [];
        $details = ['upstream_http_status' => $status];
        if ($profile !== null) {
            $details['music_client_name'] = 'WEB_REMIX';
            $details['music_client_version'] = $profile['clientVersion'];
            $details['music_visitor_context_present'] = true;
            $details['music_request_profile'] = 'oauth_minimal_v1';
        }
        if (isset($safe['status'])) { $details['upstream_status'] = $safe['status']; }
        if (isset($safe['reasons'])) { $details['upstream_reasons'] = $safe['reasons']; }
        if (in_array('ACCESS_TOKEN_SCOPE_INSUFFICIENT', $safe['reasons'] ?? [], true)
            || in_array('OAUTH_SCOPE_INVALID', $safe['reasons'] ?? [], true)) {
            throw new ServiceError('GOOGLE_MUSIC_SCOPE_REQUIRED', 'Music rejected the Google token scope. Reconnect Google with the Music grant, then reconnect ChatGPT.', $details, 403);
        }
        if ($status === 401) {
            throw new ServiceError(
                'MUSIC_RECONNECT_REQUIRED',
                'Music rejected Google authorization. Reconnect and verify Music access.',
                $details,
                401
            );
        }
        if ($status === 403) {
            throw new ServiceError('MUSIC_ACCESS_DENIED', 'Music denied access. Verify the Music account and OAuth client; reconnecting alone may not resolve this.', $details, 403);
        }
        if ($status === 429) {
            throw new ServiceError('MUSIC_RATE_LIMITED', 'Music is throttling requests. Retry later.', $details, 429);
        }
        if ($status >= 500 || ($status >= 300 && $status < 400)) {
            throw new ServiceError(
                $write ? 'UPSTREAM_UNCERTAIN' : 'MUSIC_UNAVAILABLE',
                'Music did not confirm the request.',
                $details,
                502
            );
        }
        if ($status >= 400) {
            throw new ServiceError('MUSIC_UPSTREAM_REJECTED', 'Music rejected the request. Use the safe upstream status/reasons to diagnose the client or request; extra MCP scopes will not fix this.', $details, 400);
        }
    }

    private function formatError(): never
    {
        throw new ServiceError(
            'MUSIC_FORMAT_CHANGED',
            'Music response format changed or the item is unavailable.',
            [],
            502
        );
    }

    private function searchParams(?string $filter, bool $exact): ?string
    {
        if ($filter === null) {
            return $exact ? 'EhGKAQ4IARABGAEgASgAOAFAAUICCAE%3D' : null;
        }
        if ($filter === 'playlists') {
            return 'Eg-KAQwIABAAGAAgACgBMAB' . ($exact ? 'CAggBagoQBBADEAkQBRAK' : 'qChAEEAMQCRAFEAo%3D');
        }
        $type = [
            'songs' => 'II',
            'videos' => 'IQ',
            'albums' => 'IY',
            'artists' => 'Ig'
        ][$filter] ?? throw new ServiceError('INVALID_ARGUMENT', 'Invalid Music search filter.');
        return 'EgWKAQ' . $type . ($exact ? 'AUICCAFqDBAOEAoQAxAEEAkQBQ%3D%3D' : 'AWoMEA4QChADEAQQCRAF');
    }

    private function listing(array $data, int $limit): array
    {
        $items = Parser::items($data, $limit + 1);
        return [
            'items' => array_slice($items, 0, $limit),
            'possibly_more' => count($items) > $limit || $this->hasContinuation($data),
            'upstream_prefix_only' => true
        ];
    }

    private function hasContinuation(array $data): bool
    {
        return Parser::first($data, 'nextContinuationData') !== null || Parser::first(
                $data,
                'continuationCommand'
            ) !== null || Parser::first($data, 'reloadContinuationData') !== null;
    }
}
