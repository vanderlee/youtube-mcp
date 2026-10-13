<?php
declare(strict_types=1);
namespace YouTubeMcp\Tests;

use PHPUnit\Framework\TestCase;
use YouTubeMcp\Auth\{Google, Vault};
use YouTubeMcp\Http\Client;
use YouTubeMcp\Music\{Api, Service, Tools};
use YouTubeMcp\Operations\Receipts;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Lock;

final class MusicTest extends TestCase
{
    use Fixture;
    private string $dir;
    protected function setUp(): void { $this->dir = $this->tempDir(); }
    protected function tearDown(): void { gc_collect_cycles(); $this->removeDir($this->dir); }

    private function service(array $responses): array
    {
        $db=$this->database(); $cfg=$this->config(); $locks=new Lock($this->dir); $vault=new Vault($cfg->get('APP_KEY'));
        foreach (['u1','u2'] as $user) {
            $this->user($db,$user);
            $db->execute('INSERT INTO google_connections VALUES (?,?,?,?,?,?)',[$user,'channel-'.$user,$user,
                $vault->encrypt(['access_token'=>$user.'-token','expires_at'=>time()+3600]),Google::YOUTUBE_SCOPE,'generation']);
        }
        $google=new Google($cfg,$db,$vault,new Client(),$locks);
        $api=new class($responses) extends Api {
            public array $calls=[];
            public function __construct(private array $responses) {}
            public function call(string $tool,array $arguments,?string $accessToken=null): mixed {
                $this->calls[]=[$tool,$arguments,$accessToken];
                if (!$this->responses) { throw new \LogicException('Unexpected Music call'); }
                $result=array_shift($this->responses);
                if ($result instanceof \Throwable) { throw $result; }
                return $result;
            }
        };
        return [new Service($google,$api,new Receipts($db,$locks),$locks,$cfg),$api,$db];
    }

    public function testScopesAndBoundsFailBeforeUpstream(): void
    {
        [$service,$api]=$this->service([]);
        foreach (Tools::specs() as $tool=>$spec) {
            try { $service->execute('u1',['playlists.read','playlists.write'],$tool,[]); self::fail(); }
            catch (ServiceError $e) { self::assertSame('INSUFFICIENT_SERVICE_SCOPE',$e->reason); }
        }
        foreach ([['query'=>'song','limit'=>51],['query'=>'song','filter'=>'invalid'],['query'=>'song','access_token'=>'injected']] as $args) {
            try { $service->execute('u1',['catalog.read'],'search_music',$args); self::fail(); }
            catch (ServiceError $e) { self::assertSame('INVALID_ARGUMENT',$e->reason); }
        }
        self::assertSame([],$api->calls);
    }

    public function testReadsUseOnlyTheCallingUsersGoogleTokenAndClampNestedLists(): void
    {
        [$service,$api]=$this->service([['tracks'=>array_fill(0,70,['title'=>'Song'])],['items'=>[]]]);
        $result=$service->execute('u1',['catalog.read'],'get_music_album',['browse_id'=>'MPREtest']);
        self::assertCount(50,$result['data']['tracks']); self::assertTrue($result['nested_lists_may_be_truncated']);
        $service->execute('u2',['history.read'],'get_music_history',[]);
        self::assertSame('u1-token',$api->calls[0][2]); self::assertSame('u2-token',$api->calls[1][2]);
    }

    public function testLibraryTokensCannotBeReusedForOtherUsersPurposesOrGenerations(): void
    {
        [$service,$api,$db]=$this->service([['tracks'=>[['feedbackTokens'=>['add'=>'raw-add','remove'=>'raw-remove']]]],[]]);
        $data=$service->execute('u1',['catalog.read'],'get_music_album',['browse_id'=>'MPREtest']);
        $token=$data['data']['tracks'][0]['feedbackTokens']['add']; self::assertNotSame('raw-add',$token);
        $args=['feedback_token'=>$token,'operation_key'=>'library-123'];
        $other=$service->execute('u2',['library.write'],'set_music_song_library_status',$args);
        self::assertSame('INVALID_MUSIC_TOKEN',$other['error']['code']);
        $wrongPurpose=$service->execute('u1',['history.write'],'remove_music_history_items',['feedback_tokens'=>[$token],'operation_key'=>'history-123']);
        self::assertSame('INVALID_MUSIC_TOKEN',$wrongPurpose['error']['code']);
        $ok=$service->execute('u1',['library.write'],'set_music_song_library_status',$args);
        self::assertSame('completed',$ok['status']); self::assertSame('raw-add',$api->calls[1][1]['feedback_token']);
        self::assertSame($ok,$service->execute('u1',['library.write'],'set_music_song_library_status',$args));
        $db->execute('UPDATE google_connections SET generation=? WHERE user_id=?',['new-generation','u1']);
        $changed=$service->execute('u1',['library.write'],'set_music_song_library_status',$args);
        self::assertSame('INVALID_MUSIC_TOKEN',$changed['error']['code']); self::assertCount(2,$api->calls);
    }

    public function testHistoryRemovalUsesIssuedTokenAndUncertainWriteCannotResume(): void
    {
        [$service,$api]=$this->service([[['videoId'=>'aaaaaaaaaaa','feedbackToken'=>'history-token']],
            new ServiceError('UPSTREAM_UNCERTAIN','timeout')]);
        $history=$service->execute('u1',['history.read'],'get_music_history',[]);
        $args=['feedback_tokens'=>[$history['data'][0]['feedbackToken']],'operation_key'=>'remove-history'];
        $receipt=$service->execute('u1',['history.write'],'remove_music_history_items',$args);
        self::assertSame('uncertain',$receipt['status']); self::assertSame(['history-token'],$api->calls[1][1]['feedback_tokens']);
        try { $service->execute('u1',['history.write'],'remove_music_history_items',$args+['resume'=>true]); self::fail(); }
        catch (ServiceError $e) { self::assertSame('RECONCILIATION_REQUIRED',$e->reason); }
        self::assertCount(2,$api->calls);
    }

    public function testInvalidAndExpiredTokensNeverDispatch(): void
    {
        [$service,$api]=$this->service([]);
        $data=rtrim(strtr(base64_encode(json_encode(['token'=>'raw','user'=>'u1','generation'=>'generation','purpose'=>'library','expires'=>time()-1])),'+/','-_'),'=');
        $signed=$data.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256','music-action:'.$data,str_repeat('k',32),true)),'+/','-_'),'=');
        foreach (['forged',$signed] as $n=>$token) {
            $r=$service->execute('u1',['library.write'],'set_music_song_library_status',['feedback_token'=>$token,'operation_key'=>'invalid-token-'.$n]);
            self::assertSame('INVALID_MUSIC_TOKEN',$r['error']['code']);
        }
        self::assertSame([],$api->calls);
    }

    public function testMusicToolSchemasAndWriteAnnotations(): void
    {
        $definitions=array_column(\YouTubeMcp\Mcp\Tools::definitions(),null,'name');
        foreach (Tools::specs() as $name=>$spec) {
            $json=json_decode(json_encode($definitions[$name]),true);
            self::assertSame([Tools::scope($name)],$json['_meta']['securitySchemes'][0]['scopes']);
            self::assertSame(!in_array($name,Tools::WRITES,true),$json['annotations']['readOnlyHint']);
        }
    }

}
