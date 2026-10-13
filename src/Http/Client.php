<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Http;

use YouTubeMusicMcp\ServiceError;

class Client
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array(
            $ch,
            [
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
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch);
        curl_close($ch);
        if ($raw === false || $error) {
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
