<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Auth\Google;
use YouTubeMcp\Http\RequestChecks;
use YouTubeMcp\Http\Responses;
use YouTubeMcp\Storage\Database;
use YouTubeMcp\Storage\Lock;
use YouTubeMcp\YouTube\MediaStore;

final class DeleteAccountRoute
{
    public function __construct(
        private Google $google,
        private Lock $locks,
        private MediaStore $mediaAssets,
        private Database $db,
    ) {}

    public function __invoke(ServerRequestInterface $request, string $user): ResponseInterface
    {
        RequestChecks::csrf($request);
        $this->google->disconnect($user);
        $this->locks->run(
            'account:' . $user,
            function () use ($user) {
                $this->mediaAssets->purge($user);
                $this->db->execute('DELETE FROM audit_events WHERE user_id=?', [$user]);
                $this->db->execute('DELETE FROM api_cache_versions WHERE user_id=?', [$user]);
                $this->db->execute('DELETE FROM users WHERE id=?', [$user]);
            },
        );
        $_SESSION = [];
        session_regenerate_id(true);

        return Responses::redirect('/');
    }
}
