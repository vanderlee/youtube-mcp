<?php

declare(strict_types=1);

namespace YouTubeMcp\Http;

use YouTubeMcp\ServiceError;

class Client
{
    /** Handles live only as long as this request-scoped client; never shared between users. */
    private array $handles = [];

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $origin = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . ':' . (parse_url($url, PHP_URL_PORT) ?? 443);
        // Partition connections by origin and headers, including the authorization identity.
        $key = hash('sha256', $origin . '|' . json_encode($headers, JSON_THROW_ON_ERROR));
        if (!isset($this->handles[$key])) {
            if (count($this->handles) >= 8) {
                unset($this->handles[array_key_first($this->handles)]);
            }
            $this->handles[$key] = curl_init();
        }
        $curl = $this->handles[$key];
        // Reset body/method/header options while retaining libcurl's connection and DNS caches.
        curl_reset($curl);
        curl_setopt_array(
            $curl,
            [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS
            ]
        );
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($curl);
        if ($raw === false || $curlError) {
            throw new ServiceError(
                'UPSTREAM_UNCERTAIN',
                'Upstream request did not complete. A dispatched change may have succeeded; inspect the operation before retrying.',
                [],
                502
            );
        }

        $data = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($data)) {
            throw new ServiceError('UPSTREAM_UNCERTAIN', 'Upstream response could not be decoded.', [], 502);
        }

        return ['status' => $status, 'data' => $data];
    }
}
