<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use YouTubeMcp\Data;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Auth\BrowserSession;
use YouTubeMcp\Auth\OAuth;
use YouTubeMcp\Auth\User;
use YouTubeMcp\Http\RequestChecks;
use YouTubeMcp\Http\View;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Database;
use YouTubeMcp\Storage\Lock;

final class AuthorizeClientRoute
{
    public function __construct(
        private OAuth $oauth,
        private Database $db,
        private Lock $locks,
        private BrowserSession $browser,
        private View $view,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();

        if ($method === 'GET') {
            $this->oauth->validateAuthorization($request);
            $_SESSION['authorization_query'] = $request->getQueryParams();
            $_SESSION['authorization_at'] = time();
        } else {
            RequestChecks::csrf($request);
        }
        if (!is_string($_SESSION['user'] ?? null) || $_SESSION['user'] === '') {
            return $this->view->page('Sign in', 'authorization-sign-in');
        }

        $query = $_SESSION['authorization_query'] ?? null;
        if (!$query || ($_SESSION['authorization_at'] ?? 0) < time() - 600) {
            throw new ServiceError('invalid_request', 'Authorization request expired. Reconnect from your AI client.');
        }

        $authorization = $this->oauth->validateAuthorization($request->withQueryParams(Data::array($query)));
        if (!$this->db->one('SELECT user_id FROM google_connections WHERE user_id=?', [$_SESSION['user']])) {
            return $this->view->page('Connect YouTube first', 'authorization-connect');
        }
        if ($method === 'POST') {
            $user = Data::string($_SESSION['user']);

            return $this->locks->run(
                'account:' . $user,
                function () use ($request, $authorization, $user) {
                    $this->browser->requireUser($user);
                    unset($_SESSION['authorization_query'], $_SESSION['authorization_at']);
                    $authorization->setUser(new User($user));
                    $body = (array) $request->getParsedBody();
                    $authorization->setAuthorizationApproved(($body['decision'] ?? '') === 'approve');

                    return $this->oauth->server->completeAuthorizationRequest($authorization, new Response());
                },
            );
        }

        $name = $authorization->getClient()->getName();
        $scopes = implode(', ', array_map(fn($scope) => $scope->getIdentifier(), $authorization->getScopes()));

        return $this->view->page('Authorize ' . $name, 'authorization', ['scopes' => $scopes]);
    }
}
