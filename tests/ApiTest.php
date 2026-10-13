<?php
declare(strict_types=1);
namespace YouTubeMcp\Tests;
use PHPUnit\Framework\TestCase;
use YouTubeMcp\Http\Client;
use YouTubeMcp\YouTube\Api;
use YouTubeMcp\Quota\Budget;
use YouTubeMcp\ServiceError;

final class ApiTest extends TestCase
{
    use Fixture;
    public function testCachedSearchDoesNotConsumeAnotherSearchCall(): void
    {
        $db=$this->database(); $budget=new Budget($db,$this->config());
        $http=new class extends Client { public int $calls=0; public function request(string $method,string $url,array $headers=[],?string $body=null): array { $this->calls++; return ['status'=>200,'data'=>['items'=>[]]]; } };
        $api=new Api($http,$budget,$db);
        $api->call('a','search.list',['q'=>'song'],token:'test',ttl:300);
        $api->call('a','search.list',['q'=>'song'],token:'test',ttl:300);
        self::assertSame(1,$http->calls); self::assertSame(1,$budget->status('a')['buckets']['search']['user_spent']);
    }
    public function testDailyExhaustionDoesNotRetry(): void
    {
        $db=$this->database(); $budget=new Budget($db,$this->config());
        $http=new class extends Client { public int $calls=0; public function request(string $method,string $url,array $headers=[],?string $body=null): array { $this->calls++; return ['status'=>403,'data'=>['error'=>['errors'=>[['reason'=>'quotaExceeded']]]]]; } };
        $api=new Api($http,$budget,$db);
        for ($n=0;$n<2;$n++) { try { $api->call('a','search.list',token:'test'); self::fail(); } catch (ServiceError $e) { self::assertSame('YOUTUBE_QUOTA_EXHAUSTED',$e->reason); } }
        self::assertSame(1,$http->calls);
    }
}
