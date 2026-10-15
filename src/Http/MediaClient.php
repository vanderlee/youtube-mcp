<?php

declare(strict_types=1);

namespace YouTubeMcp\Http;

use YouTubeMcp\ServiceError;

/**
 * Streams official media uploads and bounds caption downloads without following redirects.
 *
 * Provides MediaApi with a transport that handles file content separately from JSON API requests.
 */
class MediaClient
{
    /**
     * @param list<string> $headers
     * @return array{status: int, headers: array<string, string>, raw: string}
     */
    public function exchange(
        string $method,
        string $url,
        array $headers,
        ?string $body = null,
        ?string $path = null,
    ): array {
        if ($method === '') {
            throw new \InvalidArgumentException('HTTP method must not be empty.');
        }

        $raw = '';
        $responseHeaders = [];
        $file = null;
        $curl = curl_init($url);
        $this->configureTransfer($curl, $method, $headers, $raw, $responseHeaders);

        try {
            if ($path !== null) {
                $file = fopen($path, 'rb');
                if ($file === false) {
                    throw new ServiceError('MEDIA_NOT_FOUND', 'Media cannot be read.', [], 404);
                }

                $size = filesize($path);
                if ($size === false) {
                    throw new ServiceError('MEDIA_NOT_FOUND', 'Media size cannot be read.', [], 404);
                }

                curl_setopt_array($curl, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $file, CURLOPT_INFILESIZE => $size]);
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
                    502,
                );
            }

            return ['status' => $status, 'headers' => $responseHeaders, 'raw' => $raw];
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            unset($curl);
        }
    }

    /**
     * @param list<string> $headers
     * @param non-empty-string $method
     * @param array<string, string> $responseHeaders
     */
    private function configureTransfer(
        \CurlHandle $curl,
        string $method,
        array $headers,
        string &$raw,
        array &$responseHeaders,
    ): void {
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
                },
            ],
        );
    }
}
