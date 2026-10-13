<?php
declare(strict_types=1);
namespace YouTubeMusicMcp\Tests;
use PHPUnit\Framework\TestCase;
use YouTubeMusicMcp\Mcp\Presentation;

final class PresentationTest extends TestCase
{
    public function testCompactListingRetainsIdsAndPaginationWithMuchSmallerPayload(): void
    {
        $raw = ['items' => [['id' => 'PLtest123456', 'snippet' => ['title' => 'Playlist', 'description' => str_repeat('long description ', 1000), 'thumbnails' => ['default' => ['url' => 'https://example.test/image']]], 'status' => ['privacyStatus' => 'private'], 'contentDetails' => ['itemCount' => 12]]], 'nextPageToken' => 'next'];
        $r = Presentation::compact('list_playlists', $raw);
        self::assertSame('PLtest123456', $r['items'][0]['playlist_id']); self::assertSame('next', $r['next_page_token']);
        self::assertLessThan(strlen(json_encode($raw)) / 10, strlen(json_encode($r)));
        self::assertArrayHasKey('description', $raw['items'][0]['snippet']);
    }

    public function testReceiptSummaryPreservesRecoveryAndOccurrenceIdsWithoutRawSnippets(): void
    {
        $raw = ['operation_id' => 'op1', 'status' => 'uncertain', 'tool' => 'add_tracks', 'pending_step' => 'add:1', 'error' => ['code' => 'UPSTREAM_UNCERTAIN'], 'completed' => ['add:0' => ['id' => 'occurrence1', 'snippet' => ['resourceId' => ['videoId' => 'aaaaaaaaaaa'], 'description' => str_repeat('long ', 1000)]]]];
        $r = Presentation::compact('get_operation', $raw);
        self::assertSame('occurrence1', $r['entries'][0]['id']); self::assertSame('add:1', $r['pending_step']);
        self::assertSame($raw['error'], $r['error']); self::assertArrayNotHasKey('completed', $r);
    }

    public function testMusicCompactionKeepsActionTokensAndBounds(): void
    {
        $raw = ['source' => 'youtube_music_unofficial', 'data' => ['items' => [['videoId' => 'aaaaaaaaaaa', 'feedbackToken' => 'signed-token', 'thumbnails' => [['url' => 'image']]]], 'possibly_more' => true]];
        $r = Presentation::compact('get_music_history', $raw);
        self::assertSame('signed-token', $r['data']['items'][0]['feedbackToken']);
        self::assertTrue($r['data']['possibly_more']); self::assertArrayNotHasKey('thumbnails', $r['data']['items'][0]);
    }
}
