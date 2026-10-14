<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Http\Responses;
use YouTubeMcp\Storage\Database;

final class ReadyHealthRoute
{
    public function __construct(
        private Database $db,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->db->one('SELECT name FROM migrations LIMIT 1');

        return Responses::json(['status' => 'ready']);
    }
}
