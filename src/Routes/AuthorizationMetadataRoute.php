<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Auth\OAuth;
use YouTubeMcp\Http\Responses;

/**
 * Publishes OAuth authorization-server discovery metadata.
 *
 * Delegates to OAuth so clients discover the endpoints and capabilities actually configured.
 */
final class AuthorizationMetadataRoute
{
    public function __construct(
        private OAuth $oauth,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return Responses::json($this->oauth->metadata());
    }
}
