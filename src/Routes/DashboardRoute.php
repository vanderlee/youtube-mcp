<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Config;
use YouTubeMcp\Http\View;
use YouTubeMcp\Quota\Budget;
use YouTubeMcp\Storage\Database;

final class DashboardRoute
{
    public function __construct(
        private Database $db,
        private Budget $budget,
        private Config $config,
        private View $view,
    ) {}

    public function __invoke(ServerRequestInterface $request, string $user): ResponseInterface
    {
        $connection = $this->db->one('SELECT channel_id,channel_name FROM google_connections WHERE user_id=?', [$user]);
        $usage = $this->budget->status($user);

        return $this->view->page('YouTube connection', 'dashboard', [
            'connection' => $connection,
            'usage' => $usage,
            'endpoint' => $this->config->url() . '/mcp',
        ]);
    }
}
