<?php
declare(strict_types=1);
namespace YouTubeMcp\Quota;
use YouTubeMcp\Config;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Database;

final class Budget
{
    public const COSTS = ['channels.list'=>1,'videos.list'=>1,'playlists.list'=>1,'playlistItems.list'=>1,'playlists.insert'=>50,'playlists.update'=>50,'playlists.delete'=>50,'playlistItems.insert'=>50,'playlistItems.update'=>50,'playlistItems.delete'=>50,'search.list'=>1];
    private \Closure $clock;
    public function __construct(private Database $db, private Config $config, ?callable $clock = null)
    { $this->clock = $clock ? \Closure::fromCallable($clock) : fn() => time(); }
    public function now(): int { return ($this->clock)(); }
    public function window(): string { return (new \DateTimeImmutable('@'.$this->now()))->setTimezone(new \DateTimeZone('America/Los_Angeles'))->format('Y-m-d'); }
    public function reset(): int { return (new \DateTimeImmutable('@'.$this->now()))->setTimezone(new \DateTimeZone('America/Los_Angeles'))->modify('tomorrow')->setTime(0,0)->getTimestamp(); }
    public function method(string $method): array
    {
        if (!isset(self::COSTS[$method])) { throw new \LogicException('Unbudgeted YouTube method: '.$method); }
        $overrides=json_decode($this->config->get('QUOTA_METHOD_COSTS','{}'),true,32,JSON_THROW_ON_ERROR);
        $cost=$overrides[$method]??self::COSTS[$method];
        if (!is_int($cost) || $cost<1) { throw new \RuntimeException('Invalid quota method cost.'); }
        return [$method === 'search.list' ? 'search' : 'general',$cost];
    }
    private function limits(string $bucket, bool $write): array
    {
        $total = $this->config->int($bucket === 'search' ? 'QUOTA_SEARCH' : 'QUOTA_GENERAL', $bucket === 'search' ? 100 : 10000);
        $usable = (int) floor($total * (1 - (float) $this->config->get('QUOTA_HEADROOM', '0.10')));
        if ($write && $bucket === 'general') { $usable -= $this->config->int('QUOTA_RECOVERY_RESERVE', 200); }
        return [max(0,$usable), $this->config->int($bucket === 'search' ? 'USER_QUOTA_SEARCH' : 'USER_QUOTA_GENERAL', $bucket === 'search' ? 10 : 1500)];
    }
    private function row(string $window, string $owner, string $bucket): array
    {
        $this->db->execute('INSERT OR IGNORE INTO quota_windows(window,owner,bucket) VALUES (?,?,?)', [$window,$owner,$bucket]);
        return $this->db->one('SELECT * FROM quota_windows WHERE window=? AND owner=? AND bucket=?', [$window,$owner,$bucket]);
    }
    private function admit(string $user, string $bucket, int $cost, bool $write): void
    {
        $breaker = $this->db->one('SELECT * FROM quota_circuit_breakers WHERE bucket=? AND until_at>?', [$bucket,$this->now()]);
        if ($breaker) { throw new ServiceError($breaker['reason'], 'YouTube requests are temporarily unavailable for this quota bucket.', ['bucket'=>$bucket,'retry_at'=>gmdate('c',(int)$breaker['until_at'])], 429); }
        [$projectLimit,$userLimit] = $this->limits($bucket,$write);
        foreach (['project'=>$projectLimit,'user:'.$user=>$userLimit] as $owner=>$limit) {
            $row = $this->row($this->window(),$owner,$bucket);
            if ($row['spent'] + $row['reserved'] + $cost > $limit) {
                throw new ServiceError($owner === 'project' ? 'SERVICE_BUDGET_EXCEEDED' : 'USER_BUDGET_EXCEEDED', 'This operation exceeds the available daily budget. Try fewer changes or wait for reset.', ['bucket'=>$bucket,'estimated_cost'=>$cost,'remaining'=>max(0,$limit-$row['spent']-$row['reserved']),'retry_at'=>gmdate('c',$this->reset())], 429);
            }
        }
    }
    public function reserve(string $user, int $units): string
    {
        $this->cleanup();
        if ($units<1) { throw new \InvalidArgumentException('Reservation must be positive.'); }
        return $this->db->transaction(function () use ($user,$units) {
            $this->admit($user,'general',$units,true);
            $id = bin2hex(random_bytes(16)); $window = $this->window();
            $this->db->execute('INSERT INTO quota_reservations VALUES (?,?,?,?,?,?)', [$id,$user,$window,'general',$units,$this->now()+300]);
            foreach (['project','user:'.$user] as $owner) { $this->db->execute('UPDATE quota_windows SET reserved=reserved+? WHERE window=? AND owner=? AND bucket=?', [$units,$window,$owner,'general']); }
            return $id;
        });
    }
    public function dispatch(string $user, string $method, ?string $reservation = null, ?string $operation = null): string
    {
        [$bucket,$cost] = $this->method($method);
        return $this->db->transaction(function () use ($user,$method,$reservation,$operation,$bucket,$cost) {
            $window = $this->window();
            if ($reservation !== null) {
                $r = $this->db->one('SELECT * FROM quota_reservations WHERE id=? AND user_id=?', [$reservation,$user]);
                if (!$r || $r['window'] !== $window || $r['expires_at'] <= $this->now() || $r['remaining'] < $cost || $bucket !== 'general') { throw new ServiceError('RESERVATION_EXPIRED','Batch budget expired; inspect the receipt before resuming.',[],409); }
                $breaker = $this->db->one('SELECT * FROM quota_circuit_breakers WHERE bucket=? AND until_at>?', [$bucket,$this->now()]);
                if ($breaker) { throw new ServiceError($breaker['reason'],'YouTube quota bucket is unavailable.',['retry_at'=>gmdate('c',(int)$breaker['until_at'])],429); }
                $this->db->execute('UPDATE quota_reservations SET remaining=remaining-? WHERE id=?', [$cost,$reservation]);
                foreach (['project','user:'.$user] as $owner) { $this->db->execute('UPDATE quota_windows SET reserved=reserved-? WHERE window=? AND owner=? AND bucket=?', [$cost,$window,$owner,$bucket]); }
            } else { $this->admit($user,$bucket,$cost,!str_ends_with($method,'.list')); }
            foreach (['project','user:'.$user] as $owner) { $this->db->execute('UPDATE quota_windows SET spent=spent+? WHERE window=? AND owner=? AND bucket=?', [$cost,$window,$owner,$bucket]); }
            $id = bin2hex(random_bytes(16));
            $this->db->execute('INSERT INTO quota_requests VALUES (?,?,?,?,?,?,?,?,?)', [$id,$user,$operation,$method,$bucket,$cost,$window,'dispatched',$this->now()]);
            $this->alerts($window,$bucket);
            return $id;
        });
    }
    public function outcome(string $id, string $state): void { $this->db->execute('UPDATE quota_requests SET state=? WHERE id=?',[$state,$id]); }
    public function release(string $id): void
    {
        $this->db->transaction(function () use ($id) {
            $r = $this->db->one('SELECT * FROM quota_reservations WHERE id=?',[$id]); if (!$r) { return; }
            foreach (['project','user:'.$r['user_id']] as $owner) { $this->db->execute('UPDATE quota_windows SET reserved=MAX(0,reserved-?) WHERE window=? AND owner=? AND bucket=?',[$r['remaining'],$r['window'],$owner,$r['bucket']]); }
            $this->db->execute('DELETE FROM quota_reservations WHERE id=?',[$id]);
        });
    }
    public function cleanup(): void { foreach ($this->db->all('SELECT id FROM quota_reservations WHERE expires_at<=?',[$this->now()]) as $r) { $this->release($r['id']); } }
    private function alerts(string $window,string $bucket): void
    {
        $r=$this->db->one('SELECT spent FROM quota_windows WHERE window=? AND owner=? AND bucket=?',[$window,'project',$bucket]);
        $max=$this->config->int($bucket==='search' ? 'QUOTA_SEARCH' : 'QUOTA_GENERAL',$bucket==='search' ? 100 : 10000);
        foreach ([70,85,95] as $threshold) {
            if (($r['spent']??0)*100 < $threshold*$max) { continue; }
            $s=$this->db->execute('INSERT OR IGNORE INTO quota_alerts VALUES (?,?,?)',[$window,$bucket,$threshold]);
            if ($s->rowCount()) { error_log(json_encode(['event'=>'youtube_quota_threshold','bucket'=>$bucket,'percent'=>$threshold,'window'=>$window])); }
        }
    }
    public function trip(string $bucket, bool $daily): void
    { $this->db->execute('INSERT INTO quota_circuit_breakers VALUES (?,?,?) ON CONFLICT(bucket) DO UPDATE SET until_at=excluded.until_at,reason=excluded.reason',[$bucket,$daily ? $this->reset() : $this->now()+60,$daily ? 'YOUTUBE_QUOTA_EXHAUSTED' : 'YOUTUBE_RATE_LIMITED']); }
    public function rate(string $user): void
    {
        $this->db->transaction(function () use ($user) {
            $window = intdiv($this->now(),60);
            $r = $this->db->one('SELECT calls FROM rate_windows WHERE user_id=? AND window=?',[$user,$window]);
            if (($r['calls'] ?? 0) >= $this->config->int('MCP_CALLS_PER_MINUTE',10)) { throw new ServiceError('SERVICE_RATE_LIMITED','Too many tool calls. Retry next minute.',['retry_at'=>gmdate('c',($window+1)*60)],429); }
            $this->db->execute('INSERT INTO rate_windows VALUES (?,?,1) ON CONFLICT(user_id,window) DO UPDATE SET calls=calls+1',[$user,$window]);
        });
    }
    public function status(string $user): array
    {
        $result = ['reset_at'=>gmdate('c',$this->reset()),'estimated'=>true,'buckets'=>[]];
        foreach (['general','search'] as $bucket) {
            [$limit,$userLimit] = $this->limits($bucket,false);
            $u = $this->db->one('SELECT * FROM quota_windows WHERE window=? AND owner=? AND bucket=?',[$this->window(),'user:'.$user,$bucket]);
            $p = $this->db->one('SELECT * FROM quota_windows WHERE window=? AND owner=? AND bucket=?',[$this->window(),'project',$bucket]);
            $breaker = $this->db->one('SELECT * FROM quota_circuit_breakers WHERE bucket=? AND until_at>?',[$bucket,$this->now()]);
            $result['buckets'][$bucket] = ['user_limit'=>$userLimit,'user_spent'=>(int)($u['spent']??0),'user_remaining'=>max(0,$userLimit-($u['spent']??0)-($u['reserved']??0)),'service_available'=>!$breaker && ($p['spent']??0)+($p['reserved']??0)<$limit];
        }
        return $result;
    }
}
