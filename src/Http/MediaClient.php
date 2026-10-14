<?php

declare(strict_types=1);

namespace YouTubeMcp\Http;

use YouTubeMcp\ServiceError;

/** Streaming official uploads and bounded caption downloads; never follows redirects. */
class MediaClient
{
    public function exchange(
        string $method,
        string $url,
        array $headers,
        ?string $body = null,
        ?string $path = null
    ): array {
        $raw = '';
        $responseHeaders = [];
        $file = null;
        $curl = curl_init($url);
        curl_setopt_array(
            $curl,
            [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$raw): int {
                    if (strlen($raw) + strlen($chunk) > 1_048_576) {
                        return 0;
                    }

                    $raw .= $chunk;

                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2 && strtolower($parts[0]) === 'location') {
                        $responseHeaders['location'] = trim($parts[1]);
                    }

                    return strlen($line);
                }
            ]
        );

        try {
            if ($path !== null) {
                $file = fopen($path, 'rb');
                if ($file === false) {
                    throw new ServiceError('MEDIA_NOT_FOUND', 'Media cannot be read.', [], 404);
                }

                curl_setopt_array($curl, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $file, CURLOPT_INFILESIZE => filesize($path)]);
            } elseif ($body !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            }

            $result = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($result === false || curl_errno($curl)) {
                throw new ServiceError(
                    'UPSTREAM_UNCERTAIN',
                    'Media request did not complete; inspect the operation before retrying.',
                    [],
                    502
                );
            }

            return ['status' => $status, 'headers' => $responseHeaders, 'raw' => $raw];
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            curl_close($curl);
        }
    }
}
