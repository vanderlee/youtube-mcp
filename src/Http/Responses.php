<?php

declare(strict_types=1);

namespace YouTubeMcp\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

final class Responses
{
    /** @param array<array-key, mixed> $data */
    public static function json(array $data, int $status = 200): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    public static function redirect(string $url): ResponseInterface
    {
        return new Response(303, ['Location' => $url]);
    }
}
