<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Config;
use YouTubeMcp\Http\View;
use YouTubeMcp\Quota\Usage;
use YouTubeMcp\Storage\Database;

/**
 * Renders the signed-in user's account dashboard.
 *
 * Combines Database account details and Usage estimates with configured client information for View.
 */
final class DashboardRoute
{
    public function __construct(
        private Database $db,
        private Usage $usage,
        private Config $config,
        private View $view,
    ) {}

    public function __invoke(ServerRequestInterface $request, string $user): ResponseInterface
    {
        $connection = $this->db->one('SELECT channel_id,channel_name FROM google_connections WHERE user_id=?', [$user]);
        $usage = $this->usage->status($user);

        return $this->view->page('YouTube connection', 'dashboard', [
            'connection' => $connection,
            'usage' => $usage,
            'endpoint' => $this->config->url() . '/mcp',
        ]);
    }
}
