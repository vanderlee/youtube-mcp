<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use YouTubeMusicMcp\Http\Client;
use YouTubeMusicMcp\Music\{Api};
use YouTubeMusicMcp\ServiceError;

trait MusicApiTestHelpers
{
    private function api(array $responses): array
    {
        $http = new class($responses) extends Client {
            public array $calls = [];

            public function __construct(private array $responses)
            {
            }

            public function request(string $method, string $url, array $headers = [], ?string $body = null): array
            {
                $this->calls[] = [$method, $url, $headers, $body === null ? null : json_decode($body, true)];
                $r = array_shift($this->responses);
                if ($r instanceof \Throwable) {
                    throw $r;
                }
                if ($r === null) {
                    throw new \LogicException('Unexpected HTTP request');
                }
                return $r;
            }
        };
        return [
            new Api($this->config(['MUSIC_LOCATION' => 'NL', 'MUSIC_CLIENT_VERSION' => '1.20261013.01.00']), $http,
                new class($http) extends \YouTubeMusicMcp\Music\Bootstrap {
                    public function load(\YouTubeMusicMcp\Config $config): array {
                        return ['clientVersion' => $config->get('MUSIC_CLIENT_VERSION'), 'visitorData' => 'fixture-visitor'];
                    }
                }),
            $http
        ];
    }

    private function ok(array $data): array
    {
        return ['status' => 200, 'data' => $data];
    }

    private function shelf(): array
    {
        return [
            'contents' => [
                'musicShelfRenderer' => [
                    'title' => ['runs' => [['text' => 'Songs']]],
                    'contents' => [$this->row(), $this->row()],
                    'continuations' => [['nextContinuationData' => ['continuation' => 'opaque']]]
                ]
            ]
        ];
    }

    private function row(): array
    {
        return [
            'musicResponsiveListItemRenderer' => [
                'playlistItemData' => ['videoId' => 'aaaaaaaaaaa'],
                'flexColumns' => [
                    ['musicResponsiveListItemFlexColumnRenderer' => ['text' => ['runs' => [['text' => 'Test Song']]]]],
                    [
                        'musicResponsiveListItemFlexColumnRenderer' => [
                            'text' => [
                                'runs' => [
                                    [
                                        'text' => 'Artist',
                                        'navigationEndpoint' => [
                                            'browseEndpoint' => [
                                                'browseId' => 'UC' . str_repeat(
                                                        'a',
                                                        22
                                                    )
                                            ]
                                        ]
                                    ],
                                    ['text' => ' • '],
                                    [
                                        'text' => 'Album',
                                        'navigationEndpoint' => ['browseEndpoint' => ['browseId' => 'MPREtest']]
                                    ],
                                    ['text' => ' • '],
                                    ['text' => '2026']
                                ]
                            ]
                        ]
                    ],
                ],
                'fixedColumns' => [['musicResponsiveListItemFixedColumnRenderer' => ['text' => ['simpleText' => '3:21']]]],
                'overlay' => [
                    'musicItemThumbnailOverlayRenderer' => [
                        'content' => [
                            'musicPlayButtonRenderer' => [
                                'playNavigationEndpoint' => [
                                    'watchEndpoint' => [
                                        'videoId' => 'aaaaaaaaaaa',
                                        'watchEndpointMusicSupportedConfigs' => ['watchEndpointMusicConfig' => ['musicVideoType' => 'MUSIC_VIDEO_TYPE_ATV']]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'menu' => [
                    'menuRenderer' => [
                        'items' => [
                            [
                                'menuToggleServiceItemRenderer' => [
                                    'defaultIcon' => ['iconType' => 'BOOKMARK_BORDER'],
                                    'isToggled' => false,
                                    'defaultServiceEndpoint' => ['feedbackEndpoint' => ['feedbackToken' => 'raw-add']],
                                    'toggledServiceEndpoint' => ['feedbackEndpoint' => ['feedbackToken' => 'raw-remove']]
                                ]
                            ],
                            [
                                'menuToggleServiceItemRenderer' => [
                                    'defaultIcon' => ['iconType' => 'KEEP'],
                                    'defaultServiceEndpoint' => ['feedbackEndpoint' => ['feedbackToken' => 'pin-secret']]
                                ]
                            ],
                            [
                                'menuServiceItemRenderer' => [
                                    'icon' => ['iconType' => 'REMOVE_FROM_HISTORY'],
                                    'serviceEndpoint' => ['feedbackEndpoint' => ['feedbackToken' => 'history-token']]
                                ]
                            ],
                            ['menuNavigationItemRenderer' => ['navigationEndpoint' => ['browseEndpoint' => ['browseId' => 'MPTCaaaaaaaaaaa']]]],
                        ]
                    ]
                ],
            ]
        ];
    }
}

uses(MusicApiTestHelpers::class);

test('operator comparison uses identical bounded reads with only authorization differing', function (): void {
    [$api, $http] = $this->api([
        $this->ok(['contents' => ['private-account-value' => 'do-not-print']]),
        ['status' => 400, 'data' => ['error' => ['status' => 'INVALID_ARGUMENT',
            'message' => 'private-token', 'errors' => [['reason' => 'badRequest']]]]],
    ]);
    $report = $api->compareHome('private-token');
    $this->assertCount(2, $http->calls);
    $this->assertSame($http->calls[0][3], $http->calls[1][3]);
    $this->assertSame(['browseId' => 'FEmusic_home'], array_diff_key($http->calls[0][3], ['context' => true]));
    $this->assertSame($http->calls[0][1], $http->calls[1][1]);
    $this->assertSame('POST', $http->calls[0][0]);
    $this->assertNotContains('Authorization: Bearer private-token', $http->calls[0][2]);
    $this->assertContains('Authorization: Bearer private-token', $http->calls[1][2]);
    $this->assertSame(['accepted' => true, 'contents_present' => true], $report['anonymous']);
    $this->assertSame(400, $report['authenticated']['upstream_http_status']);
    $this->assertStringNotContainsString('private-token', json_encode($report));
    $this->assertStringNotContainsString('do-not-print', json_encode($report));
});

test('operator comparison requires a token and never dispatches a mutation', function (): void {
    [$api, $http] = $this->api([]);
    try { $api->compareHome(''); $this->fail('Missing credentials must stop diagnosis.'); }
    catch (ServiceError $error) { $this->assertSame('MUSIC_CONNECT_REQUIRED', $error->reason); }
    $this->assertSame([], $http->calls);
});

test('music error diagnostics retain safe codes but discard secrets and arbitrary details', function (): void {
    $raw = ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'private-token', 'details' => [
        ['reason' => 'ACCESS_TOKEN_SCOPE_INSUFFICIENT', 'metadata' => ['access_token' => 'private-token']],
        ['reason' => 'private-token'],
    ]]];
    $safe = \YouTubeMusicMcp\Music\Transport::safeError($raw);
    $this->assertSame(['error' => ['status' => 'PERMISSION_DENIED', 'reasons' => ['ACCESS_TOKEN_SCOPE_INSUFFICIENT']]], $safe);
    $this->assertSame($safe, \YouTubeMusicMcp\Music\Transport::safeError($safe));
    $this->assertStringNotContainsString('private-token', json_encode($safe));
    $this->assertSame(['error' => []], \YouTubeMusicMcp\Music\Transport::safeError(['error' => ['details' => 'malformed']]));
    [$api, $http] = $this->api([['status' => 403, 'data' => $raw]]);
    try {
        $api->call('get_music_history', [], 'dummy-token');
        $this->fail();
    } catch (ServiceError $error) {
        $this->assertSame('GOOGLE_MUSIC_SCOPE_REQUIRED', $error->reason);
        $this->assertSame(403, $error->details['upstream_http_status']);
        $this->assertSame('PERMISSION_DENIED', $error->details['upstream_status']);
        $this->assertStringNotContainsString('private-token', json_encode($error->payload()));
    }
    $this->assertCount(1, $http->calls);
});

test('music bad request retains a canonical diagnostic instead of suggesting more mcp scopes', function (): void {
    [$api] = $this->api([['status' => 400, 'data' => ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'private-secret']]]]);
    try {
        $api->call('get_music_history', [], 'dummy-token');
        $this->fail();
    } catch (ServiceError $error) {
        $this->assertSame('MUSIC_UPSTREAM_REJECTED', $error->reason);
        $this->assertSame('INVALID_ARGUMENT', $error->details['upstream_status']);
        $this->assertSame(400, $error->details['upstream_http_status']);
        $this->assertStringNotContainsString('private-secret', $error->getMessage());
    }
});

test('native search context typed result and prefix limit', function (): void {
    [$api, $http] = $this->api([$this->ok($this->shelf())]);
    $r = $api->call('search_music', ['query' => 'Song', 'filter' => 'songs', 'limit' => 1], 'user-token');
    $this->assertCount(1, $r['items']);
    $this->assertTrue($r['possibly_more']);
    $item = $r['items'][0];
    $this->assertSame('song', $item['resultType']);
    $this->assertSame('aaaaaaaaaaa', $item['videoId']);
    $this->assertSame('Artist', $item['artists'][0]['name']);
    $this->assertSame('MPREtest', $item['album']['id']);
    $this->assertSame('3:21', $item['duration']);
    $this->assertSame(['add' => 'raw-add', 'remove' => 'raw-remove'], $item['feedbackTokens']);
    $this->assertSame('MPTCaaaaaaaaaaa', $item['creditsBrowseId']);
    $this->assertArrayNotHasKey('listenAgainFeedbackTokens', $item);
    $this->assertSame('https://music.youtube.com/youtubei/v1/search?alt=json', $http->calls[0][1]);
    $this->assertContains('Authorization: Bearer user-token', $http->calls[0][2]);
    $body = $http->calls[0][3];
    $this->assertSame('EgWKAQIIAWoMEA4QChADEAQQCRAF', $body['params']);
    $this->assertSame('NL', $body['context']['client']['gl']);
    $this->assertSame('WEB_REMIX', $body['context']['client']['clientName']);
});

test('catalog album lyrics credits and account', function (): void {
    $album = $this->shelf() + [
            'header' => [
                'musicDetailHeaderRenderer' => [
                    'title' => ['runs' => [['text' => 'Test Album']]],
                    'buttons' => [['buttonRenderer' => ['navigationEndpoint' => ['watchPlaylistEndpoint' => ['playlistId' => 'OLAK5uy_test']]]]]
                ]
            ]
        ];
    [$api, $http] = $this->api([
        $this->ok($album),
        $this->ok(
            [
                'contents' => [
                    'musicDescriptionShelfRenderer' => [
                        'description' => ['runs' => [['text' => 'Lyrics text']]],
                        'footer' => ['simpleText' => 'Source']
                    ]
                ]
            ]
        ),
        $this->ok(
            [
                'contents' => [
                    'dismissableDialogContentSectionRenderer' => [
                        'title' => ['simpleText' => 'Written by'],
                        'subtitle' => ['runs' => [['text' => 'Writer']]]
                    ]
                ]
            ]
        ),
        $this->ok(
            [
                'actions' => [
                    [
                        'activeAccountHeaderRenderer' => [
                            'accountName' => ['simpleText' => 'Account'],
                            'channelHandle' => ['runs' => [['text' => '@account']]]
                        ]
                    ]
                ]
            ]
        )
    ]);
    $r = $api->call('get_music_album', ['browse_id' => 'MPREtest'], 'token');
    $this->assertSame('Test Album', $r['title']);
    $this->assertSame('OLAK5uy_test', $r['audioPlaylistId']);
    $this->assertCount(2, $r['tracks']);
    $this->assertSame('Lyrics text', $api->call('get_music_lyrics', ['browse_id' => 'MPLYtest'], 'token')['lyrics']);
    $this->assertSame(
        'Writer',
        $api->call('get_music_song_credits', ['browse_id' => 'MPTCtest'], 'token')['sections'][0]['text']
    );
    $this->assertSame('@account', $api->call('get_music_account', [], 'token')['channelHandle']);
});

test('library requests all kinds and history occurrence token', function (): void {
    [$api, $http] = $this->api(array_fill(0, 6, $this->ok($this->shelf())));
    $kinds = [
        'songs' => 'FEmusic_liked_videos',
        'albums' => 'FEmusic_liked_albums',
        'artists' => 'FEmusic_library_corpus_track_artists',
        'playlists' => 'FEmusic_liked_playlists',
        'subscriptions' => 'FEmusic_library_corpus_artists'
    ];
    foreach ($kinds as $kind => $browse) {
        $api->call('get_music_library', ['kind' => $kind, 'limit' => 1], 'token');
        $this->assertSame($browse, $http->calls[count($http->calls) - 1][3]['browseId']);
    }
    $r = $api->call('get_music_history', ['limit' => 1], 'token');
    $this->assertSame('history-token', $r['items'][0]['feedbackToken']);
    $this->assertSame('Songs', $r['items'][0]['played']);
});

test('discovery and suggestions use native endpoints', function (): void {
    $radio = [
        'contents' => [
            'playlistPanelRenderer' => [
                'contents' => [
                    [
                        'playlistPanelVideoRenderer' => [
                            'title' => ['simpleText' => 'Song'],
                            'videoId' => 'aaaaaaaaaaa'
                        ]
                    ]
                ],
                'playlistId' => 'RDtest'
            ],
            'tabs' => [
                [
                    'browseEndpoint' => [
                        'browseId' => 'MPLYlyrics',
                        'browseEndpointContextSupportedConfigs' => ['browseEndpointContextMusicConfig' => ['pageType' => 'MUSIC_PAGE_TYPE_TRACK_LYRICS']]
                    ]
                ]
            ]
        ]
    ];
    $moods = [
        'contents' => [
            'gridRenderer' => [
                'items' => [
                    [
                        'musicNavigationButtonRenderer' => [
                            'buttonText' => ['simpleText' => 'Chill'],
                            'clickCommand' => ['browseEndpoint' => ['params' => 'mood-param']]
                        ]
                    ]
                ]
            ]
        ]
    ];
    $suggestions = ['contents' => [['searchSuggestionsSectionRenderer' => ['contents' => [['searchSuggestionRenderer' => ['navigationEndpoint' => ['searchEndpoint' => ['query' => 'Suggested song']]]]]]]]];
    [$api, $http] = $this->api(
        [
            $this->ok($radio),
            $this->ok($moods),
            $this->ok($suggestions),
            $this->ok($this->shelf()),
            $this->ok($this->shelf())
        ]
    );
    $r = $api->call('get_music_radio', ['video_id' => 'aaaaaaaaaaa'], 'token');
    $this->assertSame('MPLYlyrics', $r['lyrics']);
    $this->assertCount(1, $r['tracks']);
    $this->assertSame('mood-param', $api->call('get_music_mood_categories', [], 'token')['categories'][0]['params']);
    $this->assertSame(['Suggested song'],
        $api->call('get_music_search_suggestions', ['query' => 'Song'], 'token')['items']);
    $this->assertNotEmpty($api->call('get_music_home', [], 'token')['sections']);
    $api->call('get_music_charts', ['country' => 'NL'], 'token');
    $this->assertSame(['NL'], $http->calls[4][3]['formData']['selectedValues']);
});

test('metadata never returns streaming and tracking', function (): void {
    [$api] = $this->api([
        $this->ok([
            'playabilityStatus' => ['status' => 'OK'],
            'videoDetails' => ['title' => 'Song'],
            'streamingData' => ['url' => 'secret'],
            'playbackTracking' => ['url' => 'secret']
        ])
    ]);
    $r = $api->call('get_music_song', ['video_id' => 'aaaaaaaaaaa'], 'token');
    $this->assertSame(['playabilityStatus' => ['status' => 'OK'], 'videoDetails' => ['title' => 'Song']], $r);
});

test('artist release navigation and library ordering', function (): void {
    $artist = [
        'header' => ['musicImmersiveHeaderRenderer' => ['title' => ['simpleText' => 'Artist']]],
        'contents' => [
            [
                'musicCarouselShelfRenderer' => [
                    'header' => [
                        'musicCarouselShelfBasicHeaderRenderer' => [
                            'title' => [
                                'runs' => [
                                    [
                                        'text' => 'Albums',
                                        'navigationEndpoint' => [
                                            'browseEndpoint' => [
                                                'browseId' => 'UCartist',
                                                'params' => 'release-params'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'contents' => [$this->row()]
                ]
            ]
        ]
    ];
    [$api, $http] = $this->api(
        [$this->ok($artist), $this->ok($this->shelf()), $this->ok($this->shelf()), $this->ok($this->shelf())]
    );
    $r = $api->call('get_music_artist', ['browse_id' => 'UCartist'], 'token');
    $this->assertSame('Artist', $r['title']);
    $this->assertSame(['browseId' => 'UCartist', 'params' => 'release-params'], $r['sections'][0]['navigation']);
    $api->call('get_music_artist_albums', ['browse_id' => 'UCartist', 'params' => 'release-params'], 'token');
    $this->assertSame('release-params', $http->calls[1][3]['params']);
    $api->call('get_music_library', ['kind' => 'albums', 'order' => 'recently_added'], 'token');
    $this->assertSame('ggMGKgQIABAB', $http->calls[2][3]['params']);
    $api->call('get_music_mood_playlists', ['params' => 'mood-params'], 'token');
    $this->assertSame('FEmusic_moods_and_genres_category', $http->calls[3][3]['browseId']);
});

test('write bodies feedback acknowledgments and interaction gates', function (): void {
    [$api, $http] = $this->api([
        $this->ok(['feedbackResponses' => [['isProcessed' => true]]]),
        $this->ok([]),
        $this->ok(['actions' => [['showEngagementPanelEndpoint' => ['identifier' => ['tag' => 'restricted']]]]])
    ]);
    $this->assertSame(['changed' => 1],
        $api->call('set_music_song_library_status', ['feedback_token' => 'issued-token'], 'token'));
    $this->assertSame(['issued-token'], $http->calls[0][3]['feedbackTokens']);
    $api->call('set_music_playlist_library_status', ['playlist_id' => 'PLtest', 'in_library' => false], 'token');
    $this->assertStringContainsString('/like/removelike?', $http->calls[1][1]);
    try {
        $api->call('remove_music_history_items', ['feedback_tokens' => ['token']], 'token');
        $this->fail();
    } catch (ServiceError $e) {
        $this->assertSame('MUSIC_INTERACTION_REQUIRED', $e->reason);
    }
});

test('missing feedback acknowledgment is uncertain', function (): void {
    [$api] = $this->api([$this->ok(['feedbackResponses' => [['isProcessed' => false]]])]);
    try {
        $api->call('remove_music_history_items', ['feedback_tokens' => ['token']], 'token');
        $this->fail();
    } catch (ServiceError $e) {
        $this->assertSame('UPSTREAM_UNCERTAIN', $e->reason);
    }
});

test('history addition accepts only the upstream playback endpoint', function (): void {
    foreach (
        [
            'https://evil.test/api/stats/playback',
            'http://www.youtube.com/api/stats/playback',
            'https://www.youtube.com:444/api/stats/playback'
        ] as $url
    ) {
        [$api, $http] = $this->api(
            [
                $this->ok(
                    [
                        'playabilityStatus' => ['status' => 'OK'],
                        'playbackTracking' => ['videostatsPlaybackUrl' => ['baseUrl' => $url]]
                    ]
                )
            ]
        );
        try {
            $api->call('add_music_history_item', ['video_id' => 'aaaaaaaaaaa'], 'token');
            $this->fail();
        } catch (ServiceError $e) {
            $this->assertSame('MUSIC_HISTORY_UNAVAILABLE', $e->reason);
        }
        $this->assertCount(1, $http->calls);
    }
    [$api, $http] = $this->api(
        [
            $this->ok(
                [
                    'playabilityStatus' => ['status' => 'OK'],
                    'playbackTracking' => ['videostatsPlaybackUrl' => ['baseUrl' => 'https://www.youtube.com/api/stats/playback?ei=upstream']]
                ]
            ),
            ['status' => 204, 'data' => []]
        ]
    );
    $this->assertSame(['changed' => 1],
        $api->call('add_music_history_item', ['video_id' => 'aaaaaaaaaaa'], 'token')
    );
    $this->assertSame('GET', $http->calls[1][0]);
    $this->assertStringContainsString('ei=upstream', $http->calls[1][1]);
    $this->assertStringContainsString('cpn=', $http->calls[1][1]);
});

test('upstream errors do not retry or leak details', function (): void {
    foreach (
        [
            401 => 'MUSIC_RECONNECT_REQUIRED',
            403 => 'MUSIC_ACCESS_DENIED',
            429 => 'MUSIC_RATE_LIMITED',
            500 => 'UPSTREAM_UNCERTAIN',
            302 => 'UPSTREAM_UNCERTAIN'
        ] as $status => $code
    ) {
        [$api, $http] = $this->api([['status' => $status, 'data' => ['error' => 'private-secret']]]);
        try {
            $api->call(
                'set_music_playlist_library_status',
                ['playlist_id' => 'PLtest', 'in_library' => true],
                'token'
            );
            $this->fail();
        } catch (ServiceError $e) {
            $this->assertSame($code, $e->reason);
            $this->assertStringNotContainsString('private-secret', $e->getMessage());
        }
        $this->assertCount(1, $http->calls);
    }
    [$api] = $this->api([$this->ok(['unexpected' => 'layout'])]);
    try {
        $api->call('get_music_library', ['kind' => 'songs'], 'token');
        $this->fail();
    } catch (ServiceError $e) {
        $this->assertSame('MUSIC_FORMAT_CHANGED', $e->reason);
    }
});
