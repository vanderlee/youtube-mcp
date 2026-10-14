<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Auth\Google;
use YouTubeMcp\Auth\Vault;
use YouTubeMcp\Config;
use YouTubeMcp\Http\RequestChecks;
use YouTubeMcp\Http\Responses;
use YouTubeMcp\ServiceError;

final class ConfirmGoogleConnectionRoute
{
    public function __construct(
        private Config $config,
        private Google $google,
    ) {}

    public function __invoke(ServerRequestInterface $request, string $user): ResponseInterface
    {
        RequestChecks::csrf($request);
        $pending = $_SESSION['pending_connection'] ?? null;
        if (!$pending || $pending['at'] < time() - 600 || $pending['user'] !== $user) {
            throw new ServiceError('CONNECTION_EXPIRED', 'Reconnect your Google account.');
        }

        $this->google->connect(
            $user,
            (new Vault($this->config->get('APP_KEY')))->decrypt($pending['tokens']),
            $pending['channel'],
        );
        unset($_SESSION['pending_connection']);

        return Responses::redirect('/');
    }
}
