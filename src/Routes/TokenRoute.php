<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Auth\OAuth;
use YouTubeMcp\Config;
use YouTubeMcp\Http\RequestChecks;
use YouTubeMcp\ServiceError;

/**
 * Handles OAuth code and refresh-token exchanges for the MCP resource.
 *
 * Checks the POST body and resource target before delegating token issuance to OAuth.
 */
final class TokenRoute
{
    public function __construct(
        private OAuth $oauth,
        private Config $config,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        RequestChecks::post($request);
        $body = RequestChecks::body($request);
        if (isset($body['resource']) && $body['resource'] !== $this->config->url() . '/mcp') {
            throw new ServiceError('invalid_target', 'Wrong resource.');
        }

        return $this->oauth->exchange($request->withParsedBody($body));
    }
}
