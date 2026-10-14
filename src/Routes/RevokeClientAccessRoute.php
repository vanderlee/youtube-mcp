<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Http\RequestChecks;
use YouTubeMcp\Http\Responses;
use YouTubeMcp\Storage\Database;

final class RevokeClientAccessRoute
{
    public function __construct(
        private Database $db,
    ) {}

    public function __invoke(ServerRequestInterface $request, string $user): ResponseInterface
    {
        RequestChecks::csrf($request);
        $this->db->execute('UPDATE oauth_tokens SET revoked=1 WHERE user_id=?', [$user]);

        return Responses::redirect('/');
    }
}
