<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Auth\GoogleFlow;
use YouTubeMcp\Http\RequestChecks;

final class ConnectGoogleRoute
{
    public function __construct(
        private GoogleFlow $flow,
    ) {}

    public function __invoke(ServerRequestInterface $request, string $user): ResponseInterface
    {
        RequestChecks::csrf($request);

        return $this->flow->start(true);
    }
}
