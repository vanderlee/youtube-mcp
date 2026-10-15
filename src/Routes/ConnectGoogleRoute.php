<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Auth\GoogleFlow;
use YouTubeMcp\Http\RequestChecks;

/**
 * Starts YouTube connection authorization from an authenticated browser action.
 *
 * Checks CSRF before delegating state and PKCE setup to GoogleFlow.
 */
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
