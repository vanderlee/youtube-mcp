<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\YouTube;

use YouTubeMusicMcp\Auth\Google;
use YouTubeMusicMcp\Http\Client;
use YouTubeMusicMcp\Quota\Budget;
use YouTubeMusicMcp\ServiceError;
use YouTubeMusicMcp\Storage\Database;

class Api
{
    public function __construct(
        private Client $http,
        private Budget $budget,
        private Database $db,
        private ?Google $google = null
    ) {
    }

    public function call(
        string $user,
        string $method,
        array $params = [],
        ?array $body = null,
        ?string $reservation = null,
        ?string $operation = null,
        ?string $token = null,
        int $ttl = 0
    ): array {
        [$resource, $action] = explode('.', $method);
        [$bucket] = $this->budget->method($method);
        $verb = [
            'list' => 'GET',
            'getRating' => 'GET',
            'rate' => 'POST',
            'setModerationStatus' => 'POST',
            'insert' => 'POST',
            'update' => 'PUT',
            'delete' => 'DELETE'
        ][$action];
        $path = in_array($action, ['rate', 'getRating', 'setModerationStatus'], true) ? $resource . '/' . $action : $resource;
        $key = hash('sha256', $user . '|' . $method . '|' . json_encode($params));
        if ($verb === 'GET' && $ttl > 0) {
            $cached = $this->db->one(
                'SELECT value FROM api_cache WHERE cache_key=? AND user_id=? AND expires_at>?',
                [$key, $user, time()]
            );
            if ($cached) {
                return json_decode($cached['value'], true);
            }
        }
        $access = $token ?? $this->google?->accessToken($user);
        if (!$access) {
            throw new ServiceError('GOOGLE_RECONNECT_REQUIRED', 'No connected account.', [], 401);
        }
        $attempt = 0;
        do {
            $request = $this->budget->dispatch($user, $method, $reservation, $operation);
            try {
                $response = $this->http->request(
                    $verb,
                    'https://www.googleapis.com/youtube/v3/' . $path . '?' . http_build_query($params),
                    ['Authorization: Bearer ' . $access, 'Content-Type: application/json'],
                    $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR)
                );
            } catch (ServiceError $e) {
                $this->budget->outcome($request, 'uncertain');
                throw $e;
            }
            $status = $response['status'];
            $data = $response['data'];
            $this->budget->outcome($request, $status < 300 ? 'success' : 'rejected');
            if ($status < 300) {
                if ($verb !== 'GET') {
                    $this->db->execute('DELETE FROM api_cache WHERE user_id=?', [$user]);
                } elseif ($ttl > 0) {
                    $this->db->execute(
                        'INSERT INTO api_cache VALUES (?,?,?,?) ON CONFLICT(cache_key) DO UPDATE SET value=excluded.value,expires_at=excluded.expires_at',
                        [$key, $user, json_encode($data), time() + $ttl]
                    );
                }
                return $data;
            }
            $reason = (string)($data['error']['errors'][0]['reason'] ?? 'unknown');
            if (in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true)) {
                throw new ServiceError(
                    'YOUTUBE_QUOTA_EXHAUSTED',
                    'YouTube daily quota is exhausted.',
                    ['bucket' => $bucket, 'upstream_reason' => $reason],
                    429
                );
            }
            if ($status === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true)) {
                throw new ServiceError(
                    'YOUTUBE_RATE_LIMITED',
                    'YouTube is throttling requests. Retry later.',
                    ['upstream_reason' => $reason],
                    429
                );
            }
            if ($verb === 'GET' && $status >= 500 && $attempt < 2) {
                usleep((int)(100000 * (2 ** $attempt) + random_int(0, 50000)));
                $attempt++;
                continue;
            }
            if ($status >= 500 && $verb !== 'GET') {
                throw new ServiceError(
                    'UPSTREAM_UNCERTAIN',
                    'YouTube failed while processing a change. Check the playlist before resuming.',
                    [],
                    502
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
                        'tooManyPlaylists'
                    ],
                    true
                ) => 'YOUTUBE_CHANNEL_LIMIT_REACHED',
                $status === 404 => 'YOUTUBE_NOT_FOUND',
                default => 'YOUTUBE_REQUEST_REJECTED'
            };
            throw new ServiceError(
                $code,
                'YouTube rejected this request.',
                ['upstream_reason' => $reason],
                $status === 401 ? 401 : 400
            );
        } while (true);
    }
}
