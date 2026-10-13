<?php
declare(strict_types=1);
namespace YouTubeMcp\Tests;
use PHPUnit\Framework\TestCase;
use YouTubeMcp\Quota\Budget;
use YouTubeMcp\ServiceError;

final class BudgetTest extends TestCase
{
    use Fixture;
    public function testReservationsPreventOversubscriptionAndReleaseOnlyUndispatchedUnits(): void
    {
        $db=$this->database(); $b=new Budget($db,$this->config(['QUOTA_GENERAL'=>'200','QUOTA_HEADROOM'=>'0','QUOTA_RECOVERY_RESERVE'=>'0','USER_QUOTA_GENERAL'=>'200']));
        $r=$b->reserve('a',150); $b->dispatch('a','playlistItems.insert',$r); $b->release($r);
        self::assertSame(50,$b->status('a')['buckets']['general']['user_spent']);
        $r=$b->reserve('b',150);
        try { $b->reserve('a',1); self::fail('Over-admitted project budget.'); } catch (ServiceError $e) { self::assertSame('SERVICE_BUDGET_EXCEEDED',$e->reason); }
        $b->release($r); self::assertSame(150,$b->status('a')['buckets']['general']['user_remaining']);
    }
    public function testSearchBucketAndPerUserBudgetsAreIndependent(): void
    {
        $b=new Budget($this->database(),$this->config(['USER_QUOTA_SEARCH'=>'1']));
        $b->dispatch('a','search.list');
        try { $b->dispatch('a','search.list'); self::fail('Over-admitted user.'); } catch (ServiceError $e) { self::assertSame('USER_BUDGET_EXCEEDED',$e->reason); }
        $b->dispatch('b','search.list'); $b->dispatch('a','videos.list');
        self::assertSame(1,$b->status('a')['buckets']['general']['user_spent']);
    }
    public function testResetTracksPacificDaylightSaving(): void
    {
        $now=strtotime('2026-03-08T08:00:00Z');
        $b=new Budget($this->database(),$this->config(),fn()=>$now);
        self::assertSame('2026-03-08',$b->window()); self::assertSame('2026-03-09T07:00:00+00:00',gmdate('c',$b->reset()));
    }
    public function testExpiredReservationCannotDispatchAndCleanupKeepsChargedRequests(): void
    {
        $now=1000000000; $b=new Budget($this->database(),$this->config(),function () use (&$now) { return $now; });
        $r=$b->reserve('a',100); $b->dispatch('a','playlistItems.insert',$r); $now+=301;
        try { $b->dispatch('a','playlistItems.insert',$r); self::fail(); } catch (ServiceError $e) { self::assertSame('RESERVATION_EXPIRED',$e->reason); }
        $b->cleanup(); self::assertSame(50,$b->status('a')['buckets']['general']['user_spent']);
    }
    public function testUpstreamCircuitBreakerOnlyBlocksAffectedBucket(): void
    {
        $b=new Budget($this->database(),$this->config()); $b->trip('search',true); $b->dispatch('a','videos.list');
        $this->expectException(ServiceError::class); $b->dispatch('a','search.list');
    }
}
