<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Auth\OAuth;
use YouTubeMcp\Http\RequestChecks;
use YouTubeMcp\Http\Responses;

final class RegisterClientRoute
{
    public function __construct(
        private OAuth $oauth,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        RequestChecks::post($request);

        return Responses::json($this->oauth->register(RequestChecks::body($request), public: true), 201);
    }
}
