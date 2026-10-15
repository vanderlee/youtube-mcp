<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Auth\Scope;
use YouTubeMcp\Config;
use YouTubeMcp\Http\Responses;

/**
 * Publishes protected-resource metadata for the MCP endpoint.
 *
 * Uses Config and Scope to tell clients where and how to obtain service authorization.
 */
final class ResourceMetadataRoute
{
    public function __construct(
        private Config $config,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return Responses::json([
            'resource' => $this->config->url() . '/mcp',
            'authorization_servers' => [$this->config->url()],
            'scopes_supported' => Scope::SUPPORTED,
            'bearer_methods_supported' => ['header'],
        ]);
    }
}
