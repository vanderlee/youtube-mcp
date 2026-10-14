<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Auth\GoogleFlow;

final class LoginRoute
{
    public function __construct(
        private GoogleFlow $flow,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->flow->start(false);
    }
}
