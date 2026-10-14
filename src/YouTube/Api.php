<?php

declare(strict_types=1);

namespace YouTubeMcp\YouTube;

use InvalidArgumentException;
use YouTubeMcp\Auth\Google;
use YouTubeMcp\Http\Client;
use YouTubeMcp\Quota\Budget;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Database;

class Api
{
    private int $readCacheTtl = 30;
    /**
     * @var array<string, string>
     */
    private array $playlistItems = [];

    public function __construct(
        private Client $http,
        private Budget $budget,
        private Database $db,
        private ?Google $google = null,
        int $readCacheTtl = 30,
    ) {
        if (
            $readCacheTtl < 0
            || $readCacheTtl > 300
        ) {
            throw new InvalidArgumentException('Read cache TTL must be between 0 and 300 seconds.');
        }

        $this->readCacheTtl = $readCacheTtl;
    }

    /**
     * Opt-in for user-facing reads only; mutation checks keep call()'s zero-TTL default.
     *
     * @param array<string, mixed> $params
     * @return array<array-key, mixed>
     */
    public function read(string $user, string $method, array $params): array
    {
        if (
            !str_ends_with($method, '.list')
            && !str_ends_with($method, '.getRating')
        ) {
            throw new InvalidArgumentException('Only reads may use the response cache.');
        }

        return $this->call($user, $method, $params, ttl: $this->readCacheTtl);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $params
     * @return array<array-key, mixed>
     */
    public function call(
        string $user,
        string $method,
        array $params = [],
        ?array $body = null,
        ?string $reservation = null,
        ?string $operation = null,
        ?string $token = null,
        int $ttl = 0,
    ): array {
        [$resource, $action] = explode('.', $method);
        [$bucket] = $this->budget->method($method);
        [$verb, $path] = $this->route($resource, $action);
        $connection = $token === null ? $this->google?->connection($user) : null;
        // Explicit tokens (such as pending Google consent) have no confirmed connection binding.
        $cacheBinding = $connection === null ? hash('sha256', $token ?? '') : $connection['channel_id'] . '|' . $connection['generation'];
        $key = $this->cacheKey($user, $cacheBinding, $method, $params);
        $cache = new ResponseCache($this->db);
        $tags = $cache->tags($method, $params);
        if ($verb === 'GET' && $ttl > 0) {
            $cached = $cache->get($user, $key);
            if ($cached !== null) {
                return $cached;
            }
        }
        $versions = $verb === 'GET' && $ttl > 0 ? $cache->versions($user, $tags) : [];
        $itemBinding = $user . '|' . $cacheBinding . '|';
        $playlist = $body['snippet']['playlistId'] ?? (isset($params['id']) ? $this->playlistItems[$itemBinding . $params['id']] ?? null : null);

        $access = $token ?? $this->google?->accessToken($user);
        if (!$access) {
            throw new ServiceError('GOOGLE_RECONNECT_REQUIRED', 'No connected account.', [], 401);
        }

        $attempt = 0;
        do {
            $request = $this->budget->dispatch($user, $method, $reservation, $operation);
            if ($verb !== 'GET') {
                $cache->invalidate($user, $method, $params, $body, $playlist);
            }
            try {
                $response = $this->send($request, $verb, $path, $params, $access, $body);
            } finally {
                if ($verb !== 'GET') {
                    $cache->invalidate($user, $method, $params, $body, $playlist);
                }
            }

            $status = $response['status'];
            $data = $response['data'];
            $this->budget->outcome($request, $status < 300 ? 'success' : 'rejected');
            if ($status < 300) {
                if ($connection !== null) {
                    $this->requireCurrentConnection($user, $connection, $verb);
                }
                if ($resource === 'playlistItems') {
                    foreach ($data['items'] ?? [] as $entry) {
                        if (isset($entry['id'], $entry['snippet']['playlistId'])) {
                            $this->playlistItems[$itemBinding . $entry['id']] = $entry['snippet']['playlistId'];
                        }
                    }
                }
                if ($verb === 'GET' && $ttl > 0) {
                    $cache->put($user, $key, $data, $ttl, $versions);
                }

                return $data;
            }

            $this->retryOrThrow($status, $data, $verb, $bucket, $attempt);
            $attempt++;
        } while (true);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed>|null $body
     * @return array{status: int, data: array<array-key, mixed>}
     */
    private function send(string $request, string $verb, string $path, array $params, string $access, ?array $body): array
    {
        try {
            return $this->http->request(
                $verb,
                'https://www.googleapis.com/youtube/v3/' . $path . '?' . http_build_query($params),
                ['Authorization: Bearer ' . $access, 'Content-Type: application/json'],
                $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
            );
        } catch (ServiceError $e) {
            $this->budget->outcome($request, 'uncertain');
            throw $e;
        }
    }

    /** @return array{string, string} */
    private function route(string $resource, string $action): array
    {
        $verb = [
            'list' => 'GET',
            'getRating' => 'GET',
            'rate' => 'POST',
            'setModerationStatus' => 'POST',
            'insert' => 'POST',
            'update' => 'PUT',
            'delete' => 'DELETE',
        ][$action];
        $path = in_array($action, ['rate', 'getRating', 'setModerationStatus'], true) ? $resource . '/' . $action : $resource;

        return [$verb, $path];
    }

    /** @param array<string, mixed> $params */
    private function cacheKey(string $user, string $binding, string $method, array $params): string
    {
        ksort($params);

        return hash('sha256', $user . '|' . $binding . '|' . $method . '|' . json_encode($params));
    }

    /**
     * Returns only when another read attempt is permitted, after its backoff.
     * Writes and transport failures are never retried here.
     *
     * @param array<array-key, mixed> $data
     */
    private function retryOrThrow(int $status, array $data, string $verb, string $bucket, int $attempt): void
    {
        $reason = (string) ($data['error']['errors'][0]['reason'] ?? 'unknown');
        if (in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true)) {
            throw new ServiceError(
                'YOUTUBE_QUOTA_EXHAUSTED',
                'YouTube daily quota is exhausted.',
                ['bucket' => $bucket, 'upstream_reason' => $reason],
                429,
            );
        }
        if ($status === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true)) {
            throw new ServiceError(
                'YOUTUBE_RATE_LIMITED',
                'YouTube is throttling requests. Retry later.',
                ['upstream_reason' => $reason],
                429,
            );
        }
        if ($verb === 'GET' && $status >= 500 && $attempt < 2) {
            usleep((int) (100000 * (2 ** $attempt) + random_int(0, 50000)));
            return;
        }
        if ($status >= 500 && $verb !== 'GET') {
            throw new ServiceError(
                'UPSTREAM_UNCERTAIN',
                'YouTube failed while processing a change. Check the playlist before resuming.',
                [],
                502,
            );
        }

        $code = match (true) {
            $status === 401 => 'GOOGLE_RECONNECT_REQUIRED',
            $reason === 'insufficientPermissions' => 'INSUFFICIENT_GOOGLE_SCOPE',
            in_array(
                $reason,
                [
                    'playlistOperationUnsupported',
                    'playlistContainsMaximumNumberOfVideos',
                    'playlistLimitExceeded',
                    'tooManyPlaylists',
                ],
                true,
            ) => 'YOUTUBE_CHANNEL_LIMIT_REACHED',
            $status === 404 => 'YOUTUBE_NOT_FOUND',
            default => 'YOUTUBE_REQUEST_REJECTED',
        };
        throw new ServiceError(
            $code,
            'YouTube rejected this request.',
            ['upstream_reason' => $reason],
            $status === 401 ? 401 : 400,
        );
    }

    /**
     * @param array{channel_id: string, generation: string} $connection
     */
    private function requireCurrentConnection(string $user, array $connection, string $verb): void
    {
        $current = $this->db->one('SELECT channel_id,generation FROM google_connections WHERE user_id=?', [$user]);
        if (
            !$current
            || $current['generation'] !== $connection['generation']
            || $current['channel_id'] !== $connection['channel_id']
        ) {
            if ($verb !== 'GET') {
                throw new ServiceError(
                    'UPSTREAM_UNCERTAIN',
                    'The connection changed after a dispatched mutation. Inspect the account and receipt before retrying.',
                    [],
                    502,
                );
            }
            throw new ServiceError(
                'GOOGLE_CONNECTION_CHANGED',
                'The account connection changed during this request. Start a new request.',
                [],
                409,
            );
        }
    }
}
