<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Http\RequestChecks;
use YouTubeMcp\Http\Responses;

final class LogoutRoute
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        RequestChecks::csrf($request);
        $_SESSION = [];
        session_regenerate_id(true);

        return Responses::redirect('/');
    }
}
