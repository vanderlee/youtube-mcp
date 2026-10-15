<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Http\Responses;

/**
 * Returns a minimal liveness response for HTTP monitoring.
 *
 * Allows the running application to be probed without querying storage.
 */
final class LiveHealthRoute
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return Responses::json(['status' => 'ok']);
    }
}
