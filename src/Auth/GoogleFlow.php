<?php

declare(strict_types=1);

namespace YouTubeMcp\Auth;

use Psr\Http\Message\ResponseInterface;
use YouTubeMcp\Auth\Google;
use YouTubeMcp\Http\Responses;

/**
 * Starts Google sign-in or YouTube authorization with state and PKCE S256.
 *
 * Stores callback context in the browser session and delegates the authorization URL to Google.
 */
final class GoogleFlow
{
    public function __construct(
        private Google $google,
    ) {}

    public function start(bool $youtube): ResponseInterface
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(24));
        $_SESSION['google_flow'] = [
            'state' => $state,
            'verifier' => $verifier,
            'youtube' => $youtube,
            'user' => $_SESSION['user'] ?? null,
            'at' => time(),
        ];
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return Responses::redirect($this->google->authorize($state, $challenge, $youtube));
    }
}
