<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\YouTube;

use YouTubeMusicMcp\Auth\{Google, Vault};
use YouTubeMusicMcp\Http\MediaClient;
use YouTubeMusicMcp\Quota\Budget;
use YouTubeMusicMcp\ServiceError;

final class MediaApi
{
    public function __construct(private Google $google, private Budget $budget, private Vault $vault, private MediaClient $http) {}

    public function upload(string $user, string $method, array $params, array $metadata, array $asset, callable $step): array
    {
        $resource = explode('.', $method)[0];
        if (!in_array($method, ['videos.insert', 'captions.insert', 'captions.update', 'thumbnails.set', 'channelBanners.insert'], true)) {
            throw new ServiceError('INVALID_ARGUMENT', 'Unsupported upload method.');
        }
        $session = $step('upload-session', function () use ($user, $method, $resource, $params, $metadata, $asset) {
            $url = 'https://www.googleapis.com/upload/youtube/v3/' . $resource
                . ($method === 'thumbnails.set' ? '/set' : ($method === 'channelBanners.insert' ? '/insert' : ''))
                . '?' . http_build_query(['uploadType' => 'resumable'] + $params);
            $id = $this->budget->dispatch($user, $method);
            try {
                $response = $this->http->exchange($method === 'captions.update' ? 'PUT' : 'POST', $url,
                    ['Authorization: Bearer ' . $this->google->accessToken($user), 'Content-Type: application/json',
                        'X-Upload-Content-Type: ' . $asset['mime'], 'X-Upload-Content-Length: ' . $asset['bytes']], json_encode((object)$metadata, JSON_THROW_ON_ERROR));
            } catch (ServiceError $error) { $this->budget->outcome($id, 'uncertain'); throw $error; }
            $this->budget->outcome($id, $response['status'] < 300 ? 'success' : 'rejected');
            $this->check($response['status']);
            $location = $response['headers']['location'] ?? '';
            self::validateLocation($location);
            return ['session' => $this->vault->encrypt(['location' => $location])];
        });
        $location = $this->vault->decrypt($session['session'])['location'];
        self::validateLocation($location);
        return $step('upload-content', function () use ($user, $location, $asset) {
            $response = $this->http->exchange('PUT', $location, ['Authorization: Bearer ' . $this->google->accessToken($user),
                'Content-Type: ' . $asset['mime'], 'Content-Length: ' . $asset['bytes']], path: $asset['path']);
            if ($response['status'] === 308) { throw new ServiceError('UPSTREAM_UNCERTAIN', 'Upload is incomplete. Reconcile its saved receipt; automatic retransmission is disabled.', [], 502); }
            $this->check($response['status']);
            $data = json_decode($response['raw'], true);
            if (!is_array($data) || isset($data['error'])) { throw new ServiceError('UPSTREAM_UNCERTAIN', 'Upload response was not valid success JSON.', [], 502); }
            // Only identifiers and the banner image URL are required by subsequent steps.
            return array_intersect_key($data, array_flip(['id', 'url']));
        });
    }

    public function caption(string $user, string $id, string $format): array
    {
        $request = $this->budget->dispatch($user, 'captions.download');
        try {
            $response = $this->http->exchange('GET', 'https://www.googleapis.com/youtube/v3/captions/' . rawurlencode($id) . '?tfmt=' . rawurlencode($format),
                ['Authorization: Bearer ' . $this->google->accessToken($user)]);
        } catch (ServiceError $error) { $this->budget->outcome($request, 'uncertain'); throw $error; }
        $this->budget->outcome($request, $response['status'] < 300 ? 'success' : 'rejected');
        $this->check($response['status']);
        return ['caption_id' => $id, 'format' => $format, 'content' => $response['raw']];
    }

    public static function validateLocation(string $url): void
    {
        $parts = parse_url($url);
        if (preg_match('/[\x00-\x20\x7f]/', $url) || !$parts || ($parts['scheme'] ?? '') !== 'https' || !in_array($parts['host'] ?? '', ['www.googleapis.com', 'youtube.googleapis.com'], true)
            || !str_starts_with($parts['path'] ?? '', '/upload/youtube/v3/') || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            throw new ServiceError('UPSTREAM_UNCERTAIN', 'Google returned an unsupported upload session URL; no file was transmitted.', [], 502);
        }
    }

    private function check(int $status): void
    {
        if ($status >= 500) { throw new ServiceError('UPSTREAM_UNCERTAIN', 'Google failed during media processing; reconcile before retrying.', [], 502); }
        if ($status >= 300) { throw new ServiceError('YOUTUBE_MEDIA_REJECTED', 'Google rejected the media request.', ['upstream_http_status' => $status], 400); }
    }
}
