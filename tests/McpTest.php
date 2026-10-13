<?php
declare(strict_types=1);
namespace YouTubeMcp\Tests;
use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use YouTubeMcp\{App,Config};
use YouTubeMcp\Auth\{OAuthRepository,Client,Scope};
use YouTubeMcp\Storage\Database;

final class McpTest extends TestCase
{
    use Fixture;
    private string $dir;
    private App $app;
    private string $token;
    protected function setUp(): void
    {
        $this->dir=$this->tempDir(); $key=openssl_pkey_new(['private_key_bits'=>2048]); openssl_pkey_export($key,$private);
        file_put_contents($this->dir.'/oauth-private.pem',$private); file_put_contents($this->dir.'/oauth-public.pem',openssl_pkey_get_details($key)['key']);
        $db=new Database($this->dir.'/service.sqlite'); $db->migrate(dirname(__DIR__)); $this->user($db);
        $repo=new OAuthRepository($db,'https://music.example.test'); $token=$repo->getNewToken(new Client('test','test',['https://chatgpt.com/callback']),[new Scope('playlists.read')],'u1');
        $token->setIdentifier('test-token'); $token->setExpiryDateTime(new \DateTimeImmutable('+1 hour')); $token->setPrivateKey(new \League\OAuth2\Server\CryptKey($private,null,false)); $repo->persistNewAccessToken($token);
        $this->token=$token->toString(); $this->app=new App($this->config(['STORAGE_PATH'=>$this->dir]));
    }
    protected function tearDown(): void { unset($this->app); gc_collect_cycles(); $this->removeDir($this->dir); }
    private function rpc(array $payload,?string $session=null): \Psr\Http\Message\ResponseInterface
    {
        $headers=['Host'=>'music.example.test','Authorization'=>'Bearer '.$this->token,'Content-Type'=>'application/json','Accept'=>'application/json, text/event-stream'];
        if ($session) { $headers['Mcp-Session-Id']=$session; $headers['Mcp-Protocol-Version']='2025-06-18'; }
        return $this->app->handle(new ServerRequest('POST','https://music.example.test/mcp',$headers,json_encode($payload)));
    }
    public function testSdkHandshakeDiscoveryAndLocalUsageTool(): void
    {
        $r=$this->rpc(['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize','params'=>['protocolVersion'=>'2025-06-18','capabilities'=>new \stdClass(),'clientInfo'=>['name'=>'test','version'=>'1']]]);
        self::assertSame(200,$r->getStatusCode(),(string)$r->getBody()); $session=$r->getHeaderLine('Mcp-Session-Id'); self::assertNotEmpty($session);
        $this->rpc(['jsonrpc'=>'2.0','method'=>'notifications/initialized'],$session);
        $list=$this->rpc(['jsonrpc'=>'2.0','id'=>2,'method'=>'tools/list'],$session);
        self::assertSame(200,$list->getStatusCode(),(string)$list->getBody()); self::assertStringContainsString('add_tracks',(string)$list->getBody());
        $usage=$this->rpc(['jsonrpc'=>'2.0','id'=>3,'method'=>'tools/call','params'=>['name'=>'get_usage_status','arguments'=>new \stdClass()]],$session);
        self::assertSame(200,$usage->getStatusCode(),(string)$usage->getBody()); self::assertStringContainsString('user_remaining',(string)$usage->getBody());
    }
    public function testUnauthorizedEndpointReturnsDiscoveryChallenge(): void
    {
        $r=$this->app->handle(new ServerRequest('POST','https://music.example.test/mcp',['Host'=>'music.example.test']));
        self::assertSame(401,$r->getStatusCode()); self::assertStringContainsString('oauth-protected-resource/mcp',$r->getHeaderLine('WWW-Authenticate'));
    }
    public function testDuplicateHostHeadersDoNotBlockAuthenticatedInitialization(): void
    {
        $payload=['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize','params'=>['protocolVersion'=>'2025-06-18','capabilities'=>new \stdClass(),'clientInfo'=>['name'=>'test','version'=>'1']]];
        $headers=['Host'=>['music.example.test','music.example.test'],'Authorization'=>'Bearer '.$this->token,'Content-Type'=>'application/json','Accept'=>'application/json, text/event-stream'];
        $r=$this->app->handle(new ServerRequest('POST','https://music.example.test/mcp',$headers,json_encode($payload)));
        self::assertSame(200,$r->getStatusCode(),(string)$r->getBody());
        $invalid=$this->app->handle(new ServerRequest('POST','https://attacker.test/mcp',$headers,json_encode($payload)));
        self::assertSame(400,$invalid->getStatusCode());
        self::assertStringContainsString('INVALID_HOST',(string)$invalid->getBody());
    }
    public function testBrowserPoliciesPermitGoogleAndChatGptFormRedirects(): void
    {
        $r=$this->app->handle(new ServerRequest('GET','https://music.example.test/health/live'));
        self::assertSame('same-origin',$r->getHeaderLine('Referrer-Policy'));
        self::assertStringContainsString("form-action 'self' https://accounts.google.com https://chatgpt.com;",$r->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString("frame-ancestors 'none'",$r->getHeaderLine('Content-Security-Policy'));
    }
    public function testSameOriginAllowedButNullAndForeignOriginsRejected(): void
    {
        foreach (['https://music.example.test'=>200,'null'=>403,'https://attacker.test'=>403] as $origin=>$status) {
            $r=$this->app->handle((new ServerRequest('GET','https://music.example.test/health/live'))->withHeader('Origin',$origin));
            self::assertSame($status,$r->getStatusCode());
        }
    }
}
