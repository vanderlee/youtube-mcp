<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Auth\GoogleFlow;

/**
 * Starts browser sign-in through Google.
 *
 * Delegates to GoogleFlow so login shares the state and PKCE setup used for connection authorization.
 */
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
