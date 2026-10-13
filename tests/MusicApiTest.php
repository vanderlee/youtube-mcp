<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use PHPUnit\Framework\TestCase;
use YouTubeMusicMcp\Http\Client;
use YouTubeMusicMcp\Music\{Api};
use YouTubeMusicMcp\ServiceError;

final class MusicApiTest extends TestCase
{
    use Fixture;

    public function testNativeSearchContextTypedResultAndPrefixLimit(): void
    {
        [$api, $http] = $this->api([$this->ok($this->shelf())]);
        $r = $api->call('search_music', ['query' => 'Song', 'filter' => 'songs', 'limit' => 1], 'user-token');
        self::assertCount(1, $r['items']);
        self::assertTrue($r['possibly_more']);
        $item = $r['items'][0];
        self::assertSame('song', $item['resultType']);
        self::assertSame('aaaaaaaaaaa', $item['videoId']);
        self::assertSame('Artist', $item['artists'][0]['name']);
        self::assertSame('MPREtest', $item['album']['id']);
        self::assertSame('3:21', $item['duration']);
        self::assertSame(['add' => 'raw-add', 'remove' => 'raw-remove'], $item['feedbackTokens']);
        self::assertSame('MPTCaaaaaaaaaaa', $item['creditsBrowseId']);
        self::assertArrayNotHasKey('listenAgainFeedbackTokens', $item);
        self::assertSame('https://music.youtube.com/youtubei/v1/search?alt=json', $http->calls[0][1]);
        self::assertContains('Authorization: Bearer user-token', $http->calls[0][2]);
        $body = $http->calls[0][3];
        self::assertSame('EgWKAQIIAWoMEA4QChADEAQQCRAF', $body['params']);
        self::assertSame('NL', $body['context']['client']['gl']);
        self::assertSame('WEB_REMIX', $body['context']['client']['clientName']);
    }

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
            new Api($this->config(['MUSIC_LOCATION' => 'NL', 'MUSIC_CLIENT_VERSION' => '1.20261013.01.00']), $http),
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

    public function testCatalogAlbumLyricsCreditsAndAccount(): void
    {
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
        self::assertSame('Test Album', $r['title']);
        self::assertSame('OLAK5uy_test', $r['audioPlaylistId']);
        self::assertCount(2, $r['tracks']);
        self::assertSame('Lyrics text', $api->call('get_music_lyrics', ['browse_id' => 'MPLYtest'], 'token')['lyrics']);
        self::assertSame(
            'Writer',
            $api->call('get_music_song_credits', ['browse_id' => 'MPTCtest'], 'token')['sections'][0]['text']
        );
        self::assertSame('@account', $api->call('get_music_account', [], 'token')['channelHandle']);
    }

    public function testLibraryRequestsAllKindsAndHistoryOccurrenceToken(): void
    {
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
            self::assertSame($browse, $http->calls[count($http->calls) - 1][3]['browseId']);
        }
        $r = $api->call('get_music_history', ['limit' => 1], 'token');
        self::assertSame('history-token', $r['items'][0]['feedbackToken']);
        self::assertSame('Songs', $r['items'][0]['played']);
    }

    public function testDiscoveryAndSuggestionsUseNativeEndpoints(): void
    {
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
        self::assertSame('MPLYlyrics', $r['lyrics']);
        self::assertCount(1, $r['tracks']);
        self::assertSame('mood-param', $api->call('get_music_mood_categories', [], 'token')['categories'][0]['params']);
        self::assertSame(['Suggested song'],
            $api->call('get_music_search_suggestions', ['query' => 'Song'], 'token')['items']);
        self::assertNotEmpty($api->call('get_music_home', [], 'token')['sections']);
        $api->call('get_music_charts', ['country' => 'NL'], 'token');
        self::assertSame(['NL'], $http->calls[4][3]['formData']['selectedValues']);
    }

    public function testMetadataNeverReturnsStreamingAndTracking(): void
    {
        [$api] = $this->api([
            $this->ok([
                'playabilityStatus' => ['status' => 'OK'],
                'videoDetails' => ['title' => 'Song'],
                'streamingData' => ['url' => 'secret'],
                'playbackTracking' => ['url' => 'secret']
            ])
        ]);
        $r = $api->call('get_music_song', ['video_id' => 'aaaaaaaaaaa'], 'token');
        self::assertSame(['playabilityStatus' => ['status' => 'OK'], 'videoDetails' => ['title' => 'Song']], $r);
    }

    public function testArtistReleaseNavigationAndLibraryOrdering(): void
    {
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
        self::assertSame('Artist', $r['title']);
        self::assertSame(['browseId' => 'UCartist', 'params' => 'release-params'], $r['sections'][0]['navigation']);
        $api->call('get_music_artist_albums', ['browse_id' => 'UCartist', 'params' => 'release-params'], 'token');
        self::assertSame('release-params', $http->calls[1][3]['params']);
        $api->call('get_music_library', ['kind' => 'albums', 'order' => 'recently_added'], 'token');
        self::assertSame('ggMGKgQIABAB', $http->calls[2][3]['params']);
        $api->call('get_music_mood_playlists', ['params' => 'mood-params'], 'token');
        self::assertSame('FEmusic_moods_and_genres_category', $http->calls[3][3]['browseId']);
    }

    public function testWriteBodiesFeedbackAcknowledgmentsAndInteractionGates(): void
    {
        [$api, $http] = $this->api([
            $this->ok(['feedbackResponses' => [['isProcessed' => true]]]),
            $this->ok([]),
            $this->ok(['actions' => [['showEngagementPanelEndpoint' => ['identifier' => ['tag' => 'restricted']]]]])
        ]);
        self::assertSame(['changed' => 1],
            $api->call('set_music_song_library_status', ['feedback_token' => 'issued-token'], 'token'));
        self::assertSame(['issued-token'], $http->calls[0][3]['feedbackTokens']);
        $api->call('set_music_playlist_library_status', ['playlist_id' => 'PLtest', 'in_library' => false], 'token');
        self::assertStringContainsString('/like/removelike?', $http->calls[1][1]);
        try {
            $api->call('remove_music_history_items', ['feedback_tokens' => ['token']], 'token');
            self::fail();
        } catch (ServiceError $e) {
            self::assertSame('MUSIC_INTERACTION_REQUIRED', $e->reason);
        }
    }

    public function testMissingFeedbackAcknowledgmentIsUncertain(): void
    {
        [$api] = $this->api([$this->ok(['feedbackResponses' => [['isProcessed' => false]]])]);
        try {
            $api->call('remove_music_history_items', ['feedback_tokens' => ['token']], 'token');
            self::fail();
        } catch (ServiceError $e) {
            self::assertSame('UPSTREAM_UNCERTAIN', $e->reason);
        }
    }

    public function testHistoryAdditionAcceptsOnlyTheUpstreamPlaybackEndpoint(): void
    {
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
                self::fail();
            } catch (ServiceError $e) {
                self::assertSame('MUSIC_HISTORY_UNAVAILABLE', $e->reason);
            }
            self::assertCount(1, $http->calls);
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
        self::assertSame(['changed' => 1],
            $api->call('add_music_history_item', ['video_id' => 'aaaaaaaaaaa'], 'token')
        );
        self::assertSame('GET', $http->calls[1][0]);
        self::assertStringContainsString('ei=upstream', $http->calls[1][1]);
        self::assertStringContainsString('cpn=', $http->calls[1][1]);
    }

    public function testUpstreamErrorsDoNotRetryOrLeakDetails(): void
    {
        foreach (
            [
                401 => 'MUSIC_RECONNECT_REQUIRED',
                403 => 'MUSIC_RECONNECT_REQUIRED',
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
                self::fail();
            } catch (ServiceError $e) {
                self::assertSame($code, $e->reason);
                self::assertStringNotContainsString('private-secret', $e->getMessage());
            }
            self::assertCount(1, $http->calls);
        }
        [$api] = $this->api([$this->ok(['unexpected' => 'layout'])]);
        try {
            $api->call('get_music_library', ['kind' => 'songs'], 'token');
            self::fail();
        } catch (ServiceError $e) {
            self::assertSame('MUSIC_FORMAT_CHANGED', $e->reason);
        }
    }
}
