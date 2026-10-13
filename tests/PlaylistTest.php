<?php
declare(strict_types=1);
namespace YouTubeMcp\Tests;
use PHPUnit\Framework\TestCase;
use YouTubeMcp\Auth\{Google,Vault};
use YouTubeMcp\Http\Client;
use YouTubeMcp\Operations\Receipts;
use YouTubeMcp\Playlist\Service;
use YouTubeMcp\Quota\Budget;
use YouTubeMcp\Storage\Lock;
use YouTubeMcp\YouTube\Api;
use YouTubeMcp\ServiceError;

final class PlaylistTest extends TestCase
{
    use Fixture;
    private string $dir;
    protected function setUp(): void { $this->dir=$this->tempDir(); }
    protected function tearDown(): void { gc_collect_cycles(); $this->removeDir($this->dir); }
    private function service(array $responses,array $config=[]): array
    {
        $db=$this->database(); $this->user($db); $locks=new Lock($this->dir); $cfg=$this->config($config);
        $db->execute('INSERT INTO google_connections VALUES (?,?,?,?,?,?)',['u1','channel1','Test','unused','scope','generation1']);
        $google=new Google($cfg,$db,new Vault($cfg->get('APP_KEY')),new Client(),$locks);
        $api=new class($responses) extends Api {
            public array $calls=[];
            public function __construct(private array $responses) {}
            public function call(string $user,string $method,array $params=[],?array $body=null,?string $reservation=null,?string $operation=null,?string $token=null,int $ttl=0): array
            { $this->calls[]=[$method,$params,$body]; $r=array_shift($this->responses); if ($r instanceof \Throwable) { throw $r; } if ($r===null) { throw new \LogicException('Unexpected API call '.$method); } return $r; }
        };
        $service=new Service($google,$api,new Budget($db,$cfg),new Receipts($db,$locks),$locks);
        return [$service,$api];
    }
    private function metadata(string $channel='channel1'): array
    { return ['items'=>[['id'=>'PLtest123456','snippet'=>['channelId'=>$channel,'title'=>'Original','description'=>'Keep me'],'status'=>['privacyStatus'=>'private']]]]; }
    public function testPreferencePermissionsDoNotFollowPlaylistPermissions(): void
    {
        [$s,$api]=$this->service([]);
        foreach (['list_liked_tracks','rate_track','list_subscriptions','subscribe_artist','unsubscribe_artist'] as $tool) {
            try { $s->execute('u1',['playlists.read','playlists.write'],$tool,[]); self::fail(); }
            catch (ServiceError $e) { self::assertSame('INSUFFICIENT_SERVICE_SCOPE',$e->reason); }
        }
        self::assertSame([],$api->calls);
    }
    public function testLikedVideosPaginationAndRatings(): void
    {
        [$s,$api]=$this->service([['items'=>[], 'nextPageToken'=>'next'],['items'=>[['videoId'=>'aaaaaaaaaaa','rating'=>'like']]]]);
        $r=$s->execute('u1',['likes.read'],'list_liked_tracks',['page_token'=>'page','page_size'=>10]);
        self::assertSame('next',$r['nextPageToken']);
        self::assertSame('like',$api->calls[0][1]['myRating']);
        self::assertSame('page',$api->calls[0][1]['pageToken']);
        $s->execute('u1',['likes.read'],'get_track_ratings',['video_ids'=>['https://music.youtube.com/watch?v=aaaaaaaaaaa']]);
        self::assertSame(['id'=>'aaaaaaaaaaa'],$api->calls[1][1]);
    }
    public function testRatingReplayAndRemoval(): void
    {
        [$s,$api]=$this->service([[]]);
        $args=['video_id'=>'aaaaaaaaaaa','rating'=>'none','operation_key'=>'rate-1234'];
        $r=$s->execute('u1',['likes.write'],'rate_track',$args);
        self::assertSame('completed',$r['status']);
        self::assertSame($r,$s->execute('u1',['likes.write'],'rate_track',$args));
        self::assertCount(1,$api->calls);
        self::assertSame(['videos.rate',['id'=>'aaaaaaaaaaa','rating'=>'none'],null],$api->calls[0]);
    }
    public function testSubscriptionsResolveOwnedIdAndSkipExisting(): void
    {
        $channel='UC'.str_repeat('a',22);
        [$s,$api]=$this->service([['items'=>[['id'=>'owned-sub']]],['items'=>[['id'=>'owned-sub']]],[]]);
        $r=$s->execute('u1',['subscriptions.write'],'subscribe_artist',['channel_id'=>$channel,'operation_key'=>'sub-1234']);
        self::assertSame(0,$r['result']['changed']);
        $r=$s->execute('u1',['subscriptions.write'],'unsubscribe_artist',['channel_id'=>$channel,'operation_key'=>'unsub-1234']);
        self::assertSame('completed',$r['status']);
        self::assertSame('true',$api->calls[1][1]['mine']);
        self::assertSame($channel,$api->calls[1][1]['forChannelId']);
        self::assertSame(['subscriptions.delete',['id'=>'owned-sub'],null],$api->calls[2]);
    }
    public function testSubscriptionCreationAndAbsentRemoval(): void
    {
        $channel='UC'.str_repeat('b',22);
        [$s,$api]=$this->service([['items'=>[]],['id'=>'new-sub'],['items'=>[]]]);
        $s->execute('u1',['subscriptions.write'],'subscribe_artist',['channel_id'=>$channel,'operation_key'=>'sub-1234']);
        self::assertSame($channel,$api->calls[1][2]['snippet']['resourceId']['channelId']);
        $r=$s->execute('u1',['subscriptions.write'],'unsubscribe_artist',['channel_id'=>$channel,'operation_key'=>'unsub-1234']);
        self::assertSame(0,$r['result']['changed']); self::assertCount(3,$api->calls);
    }
    private function entry(string $id,string $video,int $position=0): array
    { return ['id'=>$id,'snippet'=>['playlistId'=>'PLtest123456','position'=>$position,'resourceId'=>['kind'=>'youtube#video','videoId'=>$video]]]; }
    public function testWriteScopeEnforcedBeforeAnyApiCall(): void
    {
        [$s,$api]=$this->service([]);
        try { $s->execute('u1',['playlists.read'],'delete_playlist',['playlist_id'=>'PLtest123456','operation_key'=>'key-1234']); self::fail(); }
        catch (ServiceError $e) { self::assertSame('INSUFFICIENT_SERVICE_SCOPE',$e->reason); }
        self::assertSame([],$api->calls);
    }
    public function testOwnershipRejectedBeforeMutation(): void
    {
        [$s,$api]=$this->service([$this->metadata('another-channel')]);
        $r=$s->execute('u1',['playlists.write'],'delete_playlist',['playlist_id'=>'PLtest123456','operation_key'=>'key-1234']);
        self::assertSame('NOT_PLAYLIST_OWNER',$r['error']['code']); self::assertCount(1,$api->calls);
    }
    public function testUpdatePreservesOmittedDescriptionAndPrivacy(): void
    {
        [$s,$api]=$this->service([$this->metadata(),['id'=>'PLtest123456']]);
        $r=$s->execute('u1',['playlists.write'],'update_playlist',['playlist_id'=>'PLtest123456','title'=>'New','operation_key'=>'key-1234']);
        self::assertSame('completed',$r['status']); $body=$api->calls[1][2];
        self::assertSame('Keep me',$body['snippet']['description']); self::assertSame('private',$body['status']['privacyStatus']);
    }
    public function testDuplicatePolicySkipsExistingAndRepeatedVideos(): void
    {
        [$s,$api]=$this->service([$this->metadata(),['items'=>[$this->entry('entry1','aaaaaaaaaaa')]],['id'=>'entry2']]);
        $r=$s->execute('u1',['playlists.write'],'add_tracks',['playlist_id'=>'PLtest123456','video_ids'=>['aaaaaaaaaaa','https://music.youtube.com/watch?v=bbbbbbbbbbb','bbbbbbbbbbb'],'operation_key'=>'key-1234']);
        self::assertSame('completed',$r['status']); self::assertCount(3,$api->calls); self::assertSame('bbbbbbbbbbb',$api->calls[2][2]['snippet']['resourceId']['videoId']);
    }
    public function testRemoveTargetsOccurrenceRatherThanVideo(): void
    {
        [$s,$api]=$this->service([$this->metadata(),['items'=>[$this->entry('first','aaaaaaaaaaa'),$this->entry('second','aaaaaaaaaaa',1)]],[]]);
        $r=$s->execute('u1',['playlists.write'],'remove_tracks',['playlist_id'=>'PLtest123456','playlist_item_ids'=>['second'],'operation_key'=>'key-1234']);
        self::assertSame('completed',$r['status']); self::assertSame(['id'=>'second'],$api->calls[2][1]);
    }
    public function testLegacyBudgetDoesNotBlockBatchWrites(): void
    {
        [$s,$api]=$this->service([$this->metadata(),[],[]],['USER_QUOTA_GENERAL'=>'50']);
        $r=$s->execute('u1',['playlists.write'],'add_tracks',['playlist_id'=>'PLtest123456','video_ids'=>['aaaaaaaaaaa','bbbbbbbbbbb'],'duplicate_policy'=>'allow','operation_key'=>'key-1234']);
        self::assertSame('completed',$r['status']); self::assertCount(3,$api->calls);
    }
    public function testSuccessfulMutationReplayConsumesNoApiRequests(): void
    {
        [$s,$api]=$this->service([['id'=>'PLtest123456']]); $args=['title'=>'Test','operation_key'=>'key-1234'];
        $first=$s->execute('u1',['playlists.write'],'create_playlist',$args); $second=$s->execute('u1',['playlists.write'],'create_playlist',$args);
        self::assertSame($first,$second); self::assertCount(1,$api->calls);
    }
}
