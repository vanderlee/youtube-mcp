<?php

declare(strict_types=1);

namespace YouTubeMcp\Quota;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use YouTubeMcp\Config;
use YouTubeMcp\Storage\Database;

/** Local usage estimates only. YouTube enforces all quotas and rate limits. */
final class Budget
{
    public const COSTS = [
        'videos.rate' => 50,
        'videos.getRating' => 1,
        'subscriptions.list' => 1,
        'subscriptions.insert' => 50,
        'subscriptions.delete' => 50,
        'channels.list' => 1,
        'videos.list' => 1,
        'playlists.list' => 1,
        'playlistItems.list' => 1,
        'search.list' => 1,
        'playlists.insert' => 50,
        'playlists.update' => 50,
        'playlists.delete' => 50,
        'playlistItems.insert' => 50,
        'playlistItems.update' => 50,
        'playlistItems.delete' => 50,
        'commentThreads.list' => 1,
        'commentThreads.insert' => 50,
        'comments.list' => 1,
        'comments.insert' => 50,
        'comments.update' => 50,
        'comments.delete' => 50,
        'comments.setModerationStatus' => 50,
        'videos.insert' => 100,
        'videos.update' => 50,
        'videos.delete' => 50,
        'videoCategories.list' => 1,
        'captions.list' => 50,
        'captions.download' => 200,
        'captions.insert' => 400,
        'captions.update' => 450,
        'captions.delete' => 50,
        'thumbnails.set' => 50,
        'channels.update' => 50,
        'channelBanners.insert' => 50,
        'channelSections.list' => 1,
        'channelSections.insert' => 50,
        'channelSections.update' => 50,
        'channelSections.delete' => 50,
    ];

    private Closure $clock;

    public function __construct(private Database $db, Config $config, ?callable $clock = null)
    {
        $this->clock = $clock ? Closure::fromCallable($clock) : fn() => time();
    }

    // Compatibility with existing operation plans; there are no admission or expiry gates.
    public function reserve(string $user, int $units): string
    {
        return bin2hex(random_bytes(16));
    }

    public function release(string $id): void {}

    public function cleanup(): void
    {
        $this->db->transaction(
            function () {
                $this->db->execute('DELETE FROM quota_reservations');
                $this->db->execute('UPDATE quota_windows SET reserved=0 WHERE reserved<>0');
                $this->db->execute('DELETE FROM quota_circuit_breakers');
            },
        );
    }

    public function dispatch(
        string $user,
        string $method,
        ?string $reservation = null,
        ?string $operation = null,
    ): string {
        [$bucket, $cost] = $this->method($method);

        return $this->db->transaction(
            function () use ($user, $method, $operation, $bucket, $cost) {
                $window = $this->window();
                foreach (['project', 'user:' . $user] as $owner) {
                    $this->db->execute(
                        'INSERT INTO quota_windows(window,owner,bucket,spent) VALUES (?,?,?,?) ON CONFLICT(window,owner,bucket) DO UPDATE SET spent=spent+excluded.spent',
                        [$window, $owner, $bucket, $cost],
                    );
                }

                $id = bin2hex(random_bytes(16));
                $this->db->execute(
                    'INSERT INTO quota_requests VALUES (?,?,?,?,?,?,?,?,?)',
                    [$id, $user, $operation, $method, $bucket, $cost, $window, 'dispatched', $this->now()],
                );

                return $id;
            },
        );
    }

    /**
     * @return array{'general'|'search', int}
     */
    public function method(string $method): array
    {
        if (!isset(self::COSTS[$method])) {
            throw new LogicException('Unknown YouTube method: ' . $method);
        }

        return [$method === 'search.list' ? 'search' : 'general', self::COSTS[$method]];
    }

    public function window(): string
    {
        return (new DateTimeImmutable('@' . $this->now()))->setTimezone(
            new DateTimeZone('America/Los_Angeles'),
        )->format('Y-m-d');
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    public function outcome(string $id, string $state): void
    {
        $this->db->execute('UPDATE quota_requests SET state=? WHERE id=?', [$state, $id]);
    }

    /**
     * @return array{
     *     reset_at: string,
     *     estimated: bool,
     *     quota_enforced_by: string,
     *     remaining_quota_known: bool,
     *     buckets: array<string, array{user_spent: int, project_spent: int}>
     * }
     */
    public function status(string $user): array
    {
        $result = [
            'reset_at' => gmdate('c', $this->reset()),
            'estimated' => true,
            'quota_enforced_by' => 'youtube',
            'remaining_quota_known' => false,
            'buckets' => [],
        ];
        foreach (['general', 'search'] as $bucket) {
            $userUsage = $this->db->one(
                'SELECT spent FROM quota_windows WHERE window=? AND owner=? AND bucket=?',
                [$this->window(), 'user:' . $user, $bucket],
            );
            $projectUsage = $this->db->one(
                'SELECT spent FROM quota_windows WHERE window=? AND owner=? AND bucket=?',
                [$this->window(), 'project', $bucket],
            );
            $result['buckets'][$bucket] = [
                'user_spent' => (int) ($userUsage['spent'] ?? 0),
                'project_spent' => (int) ($projectUsage['spent'] ?? 0),
            ];
        }

        return $result;
    }

    public function reset(): int
    {
        return (new DateTimeImmutable('@' . $this->now()))->setTimezone(
            new DateTimeZone('America/Los_Angeles'),
        )->modify('tomorrow')->setTime(0, 0)->getTimestamp();
    }
}
