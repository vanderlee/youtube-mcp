<?php

declare(strict_types=1);

namespace YouTubeMcp\Http;

use YouTubeMcp\ServiceError;

class Client
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $curl = curl_init($url);
        curl_setopt_array(
            $curl,
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
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($curl);
        curl_close($curl);
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
