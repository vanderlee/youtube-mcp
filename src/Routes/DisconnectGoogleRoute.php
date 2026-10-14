<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Auth\Google;
use YouTubeMcp\Http\RequestChecks;
use YouTubeMcp\Http\View;

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
