<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Auth;

use YouTubeMusicMcp\Config;
use YouTubeMusicMcp\Http\Client;
use YouTubeMusicMcp\ServiceError;

/** Operator-only introspection; never return Google's raw response or identifiers. */
final class MusicTokenDiagnostics
{
    public function __construct(private Config $config, private Client $http) {}

    public function inspect(string $token): array
    {
        if ($token === '') { throw new ServiceError('MUSIC_CONNECT_REQUIRED', 'A token is required.'); }
        try {
            $response = $this->http->request('POST', 'https://oauth2.googleapis.com/tokeninfo',
                ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['access_token' => $token]));
        } catch (ServiceError $error) {
            return ['checked' => false, 'code' => $error->reason];
        }
        if ($response['status'] !== 200) {
            return ['checked' => false, 'upstream_http_status' => $response['status']];
        }
        $data = $response['data'];
        $client = $data['azp'] ?? $data['issued_to'] ?? null;
        $audience = $data['aud'] ?? $data['audience'] ?? null;
        $configured = $this->config->get('MUSIC_OAUTH_CLIENT_ID');
        $expires = $data['expires_in'] ?? null;
        return ['checked' => true,
            'issued_to_music_client_matches' => is_string($client) && $configured !== '' ? hash_equals($configured, $client) : null,
            'audience_music_client_matches' => is_string($audience) && $configured !== '' ? hash_equals($configured, $audience) : null,
            'music_scope_present' => is_string($data['scope'] ?? null)
                ? in_array(Google::MUSIC_SCOPE, preg_split('/\s+/', $data['scope']), true) : null,
            'token_unexpired' => is_numeric($expires) ? (int)$expires > 0 : null];
    }
}
