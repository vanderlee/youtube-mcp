<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use YouTubeMcp\Data;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Auth\BrowserSession;
use YouTubeMcp\Auth\Google;
use YouTubeMcp\Auth\Vault;
use YouTubeMcp\Config;
use YouTubeMcp\Http\Responses;
use YouTubeMcp\Http\View;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Database;
use YouTubeMcp\YouTube\Api;

/**
 * Completes Google sign-in or prepares a YouTube connection for confirmation.
 *
 * Validates callback state and PKCE context, then coordinates Google, BrowserSession and channel discovery.
 */
final class GoogleCallbackRoute
{
    public function __construct(
        private Config $config,
        private Google $google,
        private BrowserSession $browser,
        private Database $db,
        private Api $api,
        private View $view,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $flow = $_SESSION['google_flow'] ?? null;
        unset($_SESSION['google_flow']);
        if (
            !$flow
            || Data::get($flow, 'at') < time() - 600
            || !is_string($query['state'] ?? null)
            || !hash_equals(
                Data::string(Data::get($flow, 'state')),
                $query['state'],
            )
            || !is_string($query['code'] ?? null)
            || $query['code'] === ''
        ) {
            throw new ServiceError(
                'INVALID_GOOGLE_STATE',
                'Google authorization expired or was denied. Restart sign-in.',
                [],
                400,
            );
        }

        $tokens = $this->google->exchange($query['code'], Data::string(Data::get($flow, 'verifier')));
        $identity = $this->google->identity(Data::string($tokens['access_token']));
        if (!Data::get($flow, 'youtube')) {
            $this->browser->signIn($this->google->signIn($identity));

            return Responses::redirect(
                isset($_SESSION['authorization_query']) ? '/oauth/authorize?' . http_build_query(
                    Data::array($_SESSION['authorization_query']),
                ) : '/',
            );
        }

        return $this->confirmGoogleChannel(Data::string(Data::get($flow, 'user')), $identity, $tokens);
    }

    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $tokens
     */
    private function confirmGoogleChannel(string $user, array $identity, array $tokens): ResponseInterface
    {
        $owner = $this->db->one('SELECT subject FROM users WHERE id=?', [$user]);
        if (
            !$owner
            || $owner['subject'] !== $identity['subject']
            || ($_SESSION['user'] ?? null) !== $user
        ) {
            throw new ServiceError('GOOGLE_ACCOUNT_MISMATCH', 'Connect the same Google identity used to sign in.');
        }

        $channels = $this->api->call(
            $user,
            'channels.list',
            ['part' => 'snippet', 'mine' => 'true'],
            token: Data::string($tokens['access_token']),
        );
        if (count(Data::array($channels['items'] ?? [])) !== 1) {
            throw new ServiceError(
                'CHANNEL_SELECTION_REQUIRED',
                'Google must authorize exactly one YouTube channel. Reconnect using the intended channel.',
            );
        }

        $channel = Data::get($channels, 'items', 0);
        $_SESSION['pending_connection'] = [
            'user' => $user,
            'tokens' => (new Vault($this->config->get('APP_KEY')))->encrypt($tokens),
            'channel' => $channel,
            'at' => time(),
        ];

        return $this->view->page('Confirm YouTube channel', 'confirm-channel', ['channel' => $channel]);
    }
}
