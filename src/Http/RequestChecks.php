<?php

declare(strict_types=1);

namespace YouTubeMcp\Http;

use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\ServiceError;

/**
 * Shares body parsing, POST enforcement and CSRF checks across HTTP routes.
 *
 * Keeps browser mutation guards consistent without embedding them in individual handlers.
 */
final class RequestChecks
{
    /** @return array<array-key, mixed> */
    public static function body(ServerRequestInterface $request): array
    {
        if (str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            $data = json_decode((string) $request->getBody(), true, 32, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : throw new ServiceError('INVALID_REQUEST', 'Expected an object.');
        }

        return (array) $request->getParsedBody();
    }

    public static function post(ServerRequestInterface $request): void
    {
        if ($request->getMethod() !== 'POST') {
            throw new ServiceError('METHOD_NOT_ALLOWED', 'POST required.', [], 405);
        }
    }

    public static function csrf(ServerRequestInterface $request): void
    {
        self::post($request);
        $body = (array) $request->getParsedBody();
        if (!is_string($_SESSION['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], (string) ($body['csrf'] ?? ''))) {
            throw new ServiceError('CSRF_FAILED', 'Reload the page and retry.', [], 403);
        }
    }
}
