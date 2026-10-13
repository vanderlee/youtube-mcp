<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Music;

use YouTubeMusicMcp\ServiceError;

/** Only these non-sensitive diagnostics may enter logs or the model-facing error text. */
final class Diagnostics
{
    public static function safe(array $details): array
    {
        $safe = [];
        $http = $details['upstream_http_status'] ?? null;
        if (is_int($http) && $http >= 100 && $http <= 599) { $safe['upstream_http_status'] = $http; }
        $error = Transport::safeError(['error' => ['status' => $details['upstream_status'] ?? '', 'reasons' => $details['upstream_reasons'] ?? []]])['error'] ?? [];
        if (isset($error['status'])) { $safe['upstream_status'] = $error['status']; }
        if (isset($error['reasons'])) { $safe['upstream_reasons'] = $error['reasons']; }
        if (($details['music_client_name'] ?? '') === 'WEB_REMIX') { $safe['music_client_name'] = 'WEB_REMIX'; }
        $version = $details['music_client_version'] ?? null;
        if (is_string($version) && preg_match('/^[0-9.]{8,64}$/D', $version)) { $safe['music_client_version'] = $version; }
        if (is_bool($details['music_visitor_context_present'] ?? null)) {
            $safe['music_visitor_context_present'] = $details['music_visitor_context_present'];
        }
        if (in_array($details['music_request_profile'] ?? '', ['oauth_minimal_v1', 'browser_session_v1'], true)) {
            $safe['music_request_profile'] = $details['music_request_profile'];
        }
        return $safe;
    }

    public static function text(ServiceError $error): string
    {
        $text = $error->reason . ': ' . $error->getMessage();
        $safe = self::safe($error->details);
        if (!$safe) { return $text; }
        $parts = [];
        foreach ($safe as $key => $value) {
            $parts[] = $key . '=' . (is_array($value) ? implode(',', $value) : (is_bool($value) ? ($value ? 'true' : 'false') : $value));
        }
        return $text . ' [Diagnostics: ' . implode('; ', $parts) . ']';
    }

    public static function log(ServiceError $error, string $endpoint): void
    {
        $safe = self::safe($error->details);
        if (!$safe) { return; }
        // Endpoint paths are known code constants, never URLs or caller-supplied arguments.
        if (!in_array($endpoint, ['browse', 'search', 'next', 'player', 'account/account_menu',
            'music/get_search_suggestions', 'feedback', 'like/like', 'like/removelike'], true)) { return; }
        error_log(json_encode(['event' => 'music_upstream_error', 'code' => $error->reason, 'endpoint' => $endpoint] + $safe, JSON_THROW_ON_ERROR));
    }
}
