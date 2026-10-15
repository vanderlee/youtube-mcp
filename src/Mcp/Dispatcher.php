<?php

declare(strict_types=1);

namespace YouTubeMcp\Mcp;

use YouTubeMcp\Data;
use YouTubeMcp\Auth\Google;
use YouTubeMcp\Operations\Receipts;
use YouTubeMcp\Playlist\Service as PlaylistService;
use YouTubeMcp\Playlist\Workflows;
use YouTubeMcp\Storage\Lock;
use YouTubeMcp\YouTube\CreatorService;
use YouTubeMcp\YouTube\Service as YouTubeService;

/**
 * Routes tool execution to the owning playlist, workflow or YouTube service.
 *
 * Uses ToolCatalog for ownership and reads operation receipts under the account lock.
 */
final class Dispatcher
{
    public function __construct(
        private PlaylistService $playlists,
        private YouTubeService $youtube,
        private Workflows $workflows,
        private CreatorService $creator,
        private Google $google,
        private Receipts $receipts,
        private Lock $locks,
    ) {}

    /**
     * @param list<string> $scopes
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(string $user, array $scopes, string $tool, array $arguments): array
    {
        return match (ToolCatalog::owner($tool)) {
            'creator' => $this->creator->execute($user, $scopes, $tool, $arguments),
            'youtube' => $this->youtube->execute($user, $scopes, $tool, $arguments),
            'workflow' => $this->workflows->execute($user, $scopes, $tool, $arguments),
            'receipts' => $this->locks->run(
                'account:' . $user,
                fn() => $this->receipts->get(
                    $user,
                    Data::string($arguments['operation_id']),
                    $this->google->connection($user)['generation'],
                    $scopes,
                ),
            ),
            default => $this->playlists->execute($user, $scopes, $tool, $arguments),
        };
    }
}
