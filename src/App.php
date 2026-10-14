<?php

declare(strict_types=1);

namespace YouTubeMcp;

use JsonException;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Uri;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use YouTubeMcp\Auth\BrowserSession;
use YouTubeMcp\Auth\Google;
use YouTubeMcp\Auth\GoogleFlow;
use YouTubeMcp\Auth\OAuth;
use YouTubeMcp\Auth\Vault;
use YouTubeMcp\Http\BrowserRequests;
use YouTubeMcp\Http\Client;
use YouTubeMcp\Http\MediaClient;
use YouTubeMcp\Http\Responses;
use YouTubeMcp\Http\View;
use YouTubeMcp\Operations\Receipts;
use YouTubeMcp\Playlist\Service;
use YouTubeMcp\Quota\Budget;
use YouTubeMcp\Routes;
use YouTubeMcp\Storage\Database;
use YouTubeMcp\Storage\Lock;
use YouTubeMcp\YouTube\Api;
use YouTubeMcp\YouTube\CreatorService;
use YouTubeMcp\YouTube\MediaApi;
use YouTubeMcp\YouTube\MediaStore;

final class App
{
    private Lock $locks;
    private BrowserSession $browser;

    /** @var array<string, callable(ServerRequestInterface): ResponseInterface> */
    private array $protocolRoutes;

    /** @var array<string, callable(ServerRequestInterface): ResponseInterface> */
    private array $browserRoutes;

    /** @var array<string, callable(ServerRequestInterface, string): ResponseInterface> */
    private array $accountRoutes;

    private BrowserRequests $browserRequests;
    private View $view;

    public function __construct(private Config $config)
    {
        $config->validate();

        $db = new Database($config->storage() . '/service.sqlite');
        $locks = new Lock($config->storage() . '/locks');
        $this->locks = $locks;
        $http = new Client();

        $budget = new Budget($db, $config);
        $google = new Google($config, $db, new Vault($config->get('APP_KEY')), $http, $locks);
        $api = new Api($http, $budget, $db, $google, $config->int('API_READ_CACHE_TTL', 30));
        $oauth = new OAuth($db, $config, $locks);
        $this->browser = new BrowserSession($db, $config);

        $mediaAssets = new MediaStore($db, $config);
        $mediaApi = new MediaApi(
            $google,
            $budget,
            new Vault($config->get('APP_KEY')),
            new MediaClient(),
            new \YouTubeMcp\YouTube\ResponseCache($db),
        );
        $creator = new CreatorService(
            $google,
            $api,
            new Receipts($db, $locks),
            $locks,
            $mediaAssets,
            $mediaApi,
        );
        $playlists = new Service(
            $google,
            $api,
            $budget,
            new Receipts($db, $locks),
            $locks,
            $creator,
        );

        $this->view = new View(dirname(__DIR__) . '/views');
        $this->browserRequests = new BrowserRequests($config, $this->browser);
        $flow = new GoogleFlow($google);
        $resourceMetadata = new Routes\ResourceMetadataRoute($config);
        $this->protocolRoutes = [
            '/health/live' => new Routes\LiveHealthRoute(),
            '/health/ready' => new Routes\ReadyHealthRoute($db),
            '/.well-known/oauth-authorization-server' => new Routes\AuthorizationMetadataRoute($oauth),
            '/.well-known/oauth-protected-resource' => $resourceMetadata,
            '/.well-known/oauth-protected-resource/mcp' => $resourceMetadata,
            '/oauth/register' => new Routes\RegisterClientRoute($oauth),
            '/oauth/token' => new Routes\TokenRoute($oauth, $config),
            '/mcp' => new Routes\McpRoute($config, $oauth, $playlists),
        ];
        $this->browserRoutes = [
            '/login' => new Routes\LoginRoute($flow),
            '/google/callback' => new Routes\GoogleCallbackRoute($config, $google, $this->browser, $db, $api, $this->view),
            '/logout' => new Routes\LogoutRoute(),
            '/oauth/authorize' => new Routes\AuthorizeClientRoute($oauth, $db, $locks, $this->browser, $this->view),
        ];
        $this->accountRoutes = [
            '/' => new Routes\DashboardRoute($db, $budget, $config, $this->view),
            '/media' => new Routes\MediaRoute($locks, $google, $mediaAssets, $this->view),
            '/google/connect' => new Routes\ConnectGoogleRoute($flow),
            '/google/confirm' => new Routes\ConfirmGoogleConnectionRoute($config, $google),
            '/google/disconnect' => new Routes\DisconnectGoogleRoute($google, $this->view),
            '/oauth/revoke' => new Routes\RevokeClientAccessRoute($db),
            '/account/delete' => new Routes\DeleteAccountRoute($google, $locks, $mediaAssets, $db),
        ];
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $requestId = bin2hex(random_bytes(12));
        try {
            $request = $this->prepareRequest($request, $requestId);
            $response = $this->route($request);
        } catch (OAuthServerException $e) {
            $response = $e->generateHttpResponse(new Response());
        } catch (JsonException) {
            $response = Responses::json(['code' => 'INVALID_JSON', 'message' => 'Expected a valid JSON object.'], 400);
        } catch (ServiceError $e) {
            $response = Responses::json($e->payload(), $e->httpStatus);
            if ($e->httpStatus === 401) {
                $response = $response->withHeader(
                    'WWW-Authenticate',
                    'Bearer resource_metadata="' . $this->config->url() . '/.well-known/oauth-protected-resource/mcp"',
                );
            }
        } catch (Throwable $e) {
            error_log(
                json_encode(
                    [
                        'request_id' => $requestId,
                        'error_class' => get_class($e),
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine(),
                    ] + ($e instanceof \PDOException ? Database::errorDetails($e) : []),
                ),
            );
            $response = Responses::json(
                [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'Service error. See operator logs.',
                    'request_id' => $requestId,
                ],
                500,
            );
        }

        return $response
            ->withHeader('X-Request-Id', $requestId)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'none'; style-src 'self'; form-action 'self' "
                . implode(' ', array_unique([
                    'https://accounts.google.com', 'https://chatgpt.com',
                    ...$this->config->oauthRedirectOrigins(),
                ]))
                . "; frame-ancestors 'none'; base-uri 'none'",
            );
    }

    private function prepareRequest(ServerRequestInterface $request, string $requestId): ServerRequestInterface
    {
        $this->assertTrustedOrigin($request, $requestId);

        $mediaUpload = $request->getUri()->getPath() === '/media' && $request->getMethod() === 'POST';
        $maxBytes = $mediaUpload ? MediaStore::MAX_BYTES + 65536 : 65536;
        if (
            (int) $request->getHeaderLine('Content-Length') > $maxBytes
            || $request->getBody()->getSize() > $maxBytes
        ) {
            throw new ServiceError(
                'REQUEST_TOO_LARGE',
                $mediaUpload ? 'Media request exceeds 64 MiB plus form overhead.' : 'Request exceeds 64 KiB.',
                [],
                413,
            );
        }
        // Multipart upload files are already represented by UploadedFile objects.
        // Do not load their raw request body into PHP memory.
        $raw = $mediaUpload ? '' : $request->getBody()->read(65537);
        if (strlen($raw) > $maxBytes) {
            throw new ServiceError('REQUEST_TOO_LARGE', 'Request exceeds 64 KiB.', [], 413);
        }

        $request = $request->withBody((new Psr17Factory())->createStream($raw));
        if (
            !$mediaUpload
            && str_contains($request->getHeaderLine('Content-Type'), 'application/x-www-form-urlencoded')
        ) {
            parse_str($raw, $parsed);
            $request = $request->withParsedBody($parsed);
        }
        // External origin is configured; forwarded headers never determine OAuth URLs.
        $request = $request->withUri(
            (new Uri($this->config->url()))->withPath($request->getUri()->getPath())->withQuery(
                $request->getUri()->getQuery(),
            ),
            true,
        );
        // Some proxy/client combinations supply duplicate Host values. The
        // URI host was validated above; pass one canonical authority to the SDK.
        return $request->withHeader('Host', (new Uri($this->config->url()))->getAuthority());
    }

    private function route(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (isset($this->protocolRoutes[$path])) {
            return ($this->protocolRoutes[$path])($request);
        }
        if (str_starts_with($path, '/music/')) {
            throw new ServiceError('NOT_FOUND', 'Page not found.', [], 404);
        }

        $this->browserRequests->start();
        if (isset($this->browserRoutes[$path])) {
            return ($this->browserRoutes[$path])($request);
        }
        if (empty($_SESSION['user'])) {
            return $this->view->page('YouTube Music MCP', 'welcome');
        }

        $user = $_SESSION['user'];

        return $this->locks->run(
            'account:' . $user,
            function () use ($request, $path, $user) {
                $this->browser->requireUser($user);
                if (!isset($this->accountRoutes[$path])) {
                    throw new ServiceError('NOT_FOUND', 'Page not found.', [], 404);
                }

                return ($this->accountRoutes[$path])($request, $user);
            },
        );
    }

    private function assertTrustedOrigin(ServerRequestInterface $request, string $requestId): void
    {
        if (
            strtolower($request->getUri()->getHost()) !== strtolower(
                (string) parse_url($this->config->url(), PHP_URL_HOST),
            )
        ) {
            throw new ServiceError('INVALID_HOST', 'Unexpected host.', [], 400);
        }
        if (
            $request->getHeaderLine('Origin') !== ''
            && !in_array(
                $request->getHeaderLine('Origin'),
                $this->config->clientOrigins(),
                true,
            )
        ) {
            error_log(
                json_encode(
                    [
                        'event' => 'request_origin_rejected',
                        'request_id' => $requestId,
                        'path' => $request->getUri()->getPath(),
                        'origin' => substr($request->getHeaderLine('Origin'), 0, 512),
                    ],
                ),
            );
            throw new ServiceError('INVALID_ORIGIN', 'Unexpected origin.', [], 403);
        }
    }
}
