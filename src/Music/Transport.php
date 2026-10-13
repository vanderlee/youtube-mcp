<?php
declare(strict_types=1);
namespace YouTubeMcp\Music;

use YouTubeMcp\Http\Client;
use YouTubeMcp\ServiceError;

/** Bounded native PHP transport for Music's JSON and empty playback responses. */
final class Transport extends Client
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $raw = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$raw): int {
                if (strlen($raw) + strlen($chunk) > 4_000_000) { return 0; }
                $raw .= $chunk;
                return strlen($chunk);
            }]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $result = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch);
        curl_close($ch);
        if ($result === false || $error) {
            throw new ServiceError('UPSTREAM_UNCERTAIN', 'Music request did not complete. Inspect any write before retrying.', [], 502);
        }
        // Upstream errors are mapped by the API; never expose their raw text.
        if ($status >= 300) { return ['status' => $status, 'data' => []]; }
        $data = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($data)) { throw new ServiceError('UPSTREAM_UNCERTAIN', 'Music returned an invalid JSON response.', [], 502); }
        return ['status' => $status, 'data' => $data];
    }
}
