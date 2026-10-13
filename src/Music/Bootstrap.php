<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Music;

use YouTubeMusicMcp\{Config, ServiceError};
use YouTubeMusicMcp\Http\Client;

/** Public web-client metadata only. Never fetch this page with account credentials. */
class Bootstrap
{
    private ?array $profile = null;

    public function __construct(private Client $http)
    {
    }

    public function load(Config $config): array
    {
        if ($this->profile === null) {
            try {
                $response = $this->http->request('GET', 'https://music.youtube.com/', [
                    'User-Agent: ' . self::USER_AGENT,
                    'Accept: text/html',
                    'Accept-Language: en-US,en;q=0.9',
                    'Cookie: SOCS=CAI',
                ]);
            } catch (ServiceError) {
                throw new ServiceError('MUSIC_BOOTSTRAP_UNAVAILABLE', 'Music web-client metadata could not be loaded. No account request was dispatched.', [], 502);
            }
            if ($response['status'] !== 200 || !isset($response['data']['web_client'])) {
                throw new ServiceError('MUSIC_BOOTSTRAP_UNAVAILABLE', 'Music web-client metadata could not be loaded. No account request was dispatched.', [], 502);
            }
            $this->profile = $response['data']['web_client'];
        }
        $version = $config->get('MUSIC_CLIENT_VERSION') ?: ($this->profile['clientVersion'] ?? '');
        $visitor = $this->profile['visitorData'] ?? '';
        if (!is_string($version) || !preg_match('/^[0-9.]{8,64}$/D', $version)
            || !is_string($visitor) || $visitor === '' || strlen($visitor) > 4096 || preg_match('/[\x00-\x20\x7f]/', $visitor)) {
            throw new ServiceError('MUSIC_BOOTSTRAP_UNAVAILABLE', 'Music web-client metadata is incomplete. No account request was dispatched.', [], 502);
        }
        return ['clientVersion' => $version, 'visitorData' => $visitor];
    }

    public static function parse(string $html): array
    {
        $profile = [];
        // Extract JSON string values rather than evaluating any script returned by the website.
        foreach (['INNERTUBE_CLIENT_VERSION' => 'clientVersion', 'VISITOR_DATA' => 'visitorData'] as $name => $field) {
            $pattern = '/"' . $name . '"\s*:\s*("(?:[^"\\\\]|\\\\.)*")/';
            if (preg_match($pattern, $html, $match)) {
                $value = json_decode($match[1], true);
                if (is_string($value)) { $profile[$field] = $value; }
            }
        }
        return ['web_client' => $profile];
    }

    public const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
}
