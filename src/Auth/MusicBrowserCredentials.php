<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Auth;

use YouTubeMusicMcp\ServiceError;

/** Accept cookie credentials, never a copied Authorization header or arbitrary headers. */
final class MusicBrowserCredentials
{
    public static function parse(string $cookie, string $accountIndex): array
    {
        if ($cookie === '' || strlen($cookie) > 24000 || preg_match('/[\x00-\x1f\x7f]/', $cookie)
            || !preg_match('/^[0-9]{1,2}$/D', $accountIndex)) {
            throw new ServiceError('INVALID_MUSIC_BROWSER_CREDENTIALS', 'Enter a single Cookie header value and an account index from 0 to 99.');
        }
        $cookies = [];
        foreach (explode(';', $cookie) as $entry) {
            $parts = explode('=', trim($entry), 2);
            if (count($parts) !== 2 || !preg_match('/^[A-Za-z0-9_-]+$/D', $parts[0]) || $parts[1] === ''
                || isset($cookies[$parts[0]]) || preg_match('/[\s;,]/', $parts[1])) {
                throw new ServiceError('INVALID_MUSIC_BROWSER_CREDENTIALS', 'Invalid Cookie header.');
            }
            $cookies[$parts[0]] = $parts[1];
        }
        $sapisid = $cookies['__Secure-3PAPISID'] ?? $cookies['SAPISID'] ?? null;
        if (!$sapisid) { throw new ServiceError('INVALID_MUSIC_BROWSER_CREDENTIALS', 'The Cookie value must contain SAPISID or __Secure-3PAPISID.'); }
        return ['cookie' => $cookie, 'sapisid' => $sapisid, 'account_index' => $accountIndex];
    }

    public static function headers(array $credentials, int $time): array
    {
        $origin = 'https://music.youtube.com';
        return ['Cookie: ' . $credentials['cookie'],
            'Authorization: SAPISIDHASH ' . $time . '_' . sha1($time . ' ' . $credentials['sapisid'] . ' ' . $origin),
            'X-Goog-AuthUser: ' . $credentials['account_index'], 'X-Origin: ' . $origin];
    }
}
