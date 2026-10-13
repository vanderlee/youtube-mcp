<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Music;

use YouTubeMusicMcp\Http\Client;
use YouTubeMusicMcp\ServiceError;

/** Bounded native PHP transport for Music's JSON and empty playback responses. */
final class Transport extends Client
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $raw = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$raw): int {
                if (strlen($raw) + strlen($chunk) > 4_000_000) {
                    return 0;
                }
                $raw .= $chunk;
                return strlen($chunk);
            }
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch);
        curl_close($ch);
        if ($result === false || $error) {
            throw new ServiceError(
                'UPSTREAM_UNCERTAIN',
                'Music request did not complete. Inspect any write before retrying.',
                [],
                502
            );
        }
        // Keep only allowlisted diagnostic codes; never retain upstream messages or arbitrary details.
        if ($status >= 300) {
            $decoded = json_decode($raw, true);
            return ['status' => $status, 'data' => self::safeError(is_array($decoded) ? $decoded : [])];
        }
        if ($method === 'GET' && $url === 'https://music.youtube.com/') {
            return ['status' => $status, 'data' => Bootstrap::parse($raw)];
        }
        $data = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($data)) {
            throw new ServiceError('UPSTREAM_UNCERTAIN', 'Music returned an invalid JSON response.', [], 502);
        }
        return ['status' => $status, 'data' => $data];
    }

    public static function safeError(array $data): array
    {
        $error = $data['error'] ?? null;
        if (!is_array($error)) { return []; }
        $safe = [];
        if (in_array($error['status'] ?? '', ['INVALID_ARGUMENT', 'UNAUTHENTICATED', 'PERMISSION_DENIED',
            'FAILED_PRECONDITION', 'RESOURCE_EXHAUSTED', 'NOT_FOUND', 'INTERNAL', 'UNAVAILABLE'], true)) {
            $safe['status'] = $error['status'];
        }
        $reasons = [];
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];
        foreach (array_slice($details, 0, 20) as $detail) {
            if (is_array($detail) && in_array($detail['reason'] ?? '', ['ACCESS_TOKEN_SCOPE_INSUFFICIENT',
                'API_KEY_INVALID', 'API_KEY_SERVICE_BLOCKED', 'SERVICE_DISABLED', 'OAUTH_SCOPE_INVALID',
                'UNAUTHORIZED_CLIENT', 'CONSUMER_INVALID', 'CREDENTIALS_MISSING'], true)) {
                $reasons[] = $detail['reason'];
            }
        }
        foreach (is_array($error['reasons'] ?? null) ? array_slice($error['reasons'], 0, 20) : [] as $reason) {
            if (in_array($reason, ['ACCESS_TOKEN_SCOPE_INSUFFICIENT', 'API_KEY_INVALID', 'API_KEY_SERVICE_BLOCKED',
                'SERVICE_DISABLED', 'OAUTH_SCOPE_INVALID', 'UNAUTHORIZED_CLIENT', 'CONSUMER_INVALID', 'CREDENTIALS_MISSING'], true)) {
                $reasons[] = $reason;
            }
        }
        if ($reasons) { $safe['reasons'] = array_values(array_unique($reasons)); }
        return ['error' => $safe];
    }
}
