<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Auth\Google;
use YouTubeMcp\Http\RequestChecks;
use YouTubeMcp\Http\View;

/**
 * Disconnects the user's Google authorization after a CSRF check.
 *
 * Uses Google to revoke access and View to report whether upstream revocation succeeded.
 */
final class DisconnectGoogleRoute
{
    public function __construct(
        private Google $google,
        private View $view,
    ) {}

    public function __invoke(ServerRequestInterface $request, string $user): ResponseInterface
    {
        RequestChecks::csrf($request);
        $result = $this->google->disconnect($user);

        return $this->view->page('Disconnected', 'disconnected', ['googleRevoked' => $result['google_revoked']]);
    }
}
