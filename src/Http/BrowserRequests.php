<?php

declare(strict_types=1);

namespace YouTubeMcp\Http;

use YouTubeMcp\Auth\BrowserSession;
use YouTubeMcp\Config;
use YouTubeMcp\ServiceError;

/**
 * Starts secure filesystem-backed browser sessions and initializes CSRF tokens.
 *
 * Delegates authentication validation to BrowserSession before browser routes use session state.
 */
final class BrowserRequests
{
    public function __construct(
        private Config $config,
        private BrowserSession $browser,
    ) {}

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->browser->validate();
            $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
            return;
        }
        session_name('youtube_mcp');
        if (!is_dir($this->config->storage() . '/sessions')) {
            mkdir($this->config->storage() . '/sessions', 0700, true);
        }
        session_save_path($this->config->storage() . '/sessions');
        $started = session_start(
            [
                'use_strict_mode' => 1,
                'cookie_secure' => $this->config->bool('COOKIE_SECURE', true),
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'cookie_path' => '/',
            ],
        );
        if (!$started) {
            throw new ServiceError(
                'SESSION_UNAVAILABLE',
                'Browser session storage is unavailable. Check storage permissions.',
                [],
                503,
            );
        }
        unset($_SESSION['music_authorization']);
        $this->browser->validate();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    }
}
