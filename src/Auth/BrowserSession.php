<?php

declare(strict_types=1);

namespace YouTubeMcp\Auth;

use YouTubeMcp\{Config, ServiceError};
use YouTubeMcp\Storage\Database;

/** Browser authentication is independent of the stable identity used for usage history. */
final class BrowserSession
{
    public function __construct(private Database $db, private Config $config)
    {
    }

    public function signIn(string $user): void
    {
        $this->db->execute(
            'INSERT INTO browser_accounts(user_id,generation) SELECT id,? FROM users WHERE id=? ON CONFLICT(user_id) DO NOTHING',
            [bin2hex(random_bytes(16)), $user]
        );
        $account = $this->db->one('SELECT generation FROM browser_accounts WHERE user_id=?', [$user]);
        if (!$account) {
            throw new ServiceError('AUTHENTICATION_REQUIRED', 'Sign in again.', [], 401);
        }

        $_SESSION['user'] = $user;
        $_SESSION['user_generation'] = $account['generation'];
        $_SESSION['signed_in_at'] = time();
        $_SESSION['last_seen_at'] = time();
        session_regenerate_id(true);
    }

    public function validate(): void
    {
        if (empty($_SESSION['user'])) {
            return;
        }

        $account = $this->db->one('SELECT generation FROM browser_accounts WHERE user_id=?', [$_SESSION['user']]);

        $now = time();

        $signedIn = $_SESSION['signed_in_at'] ?? 0;
        $lastSeen = $_SESSION['last_seen_at'] ?? 0;

        if (
            !$account
            || !is_string($_SESSION['user_generation'] ?? null)
            || !hash_equals($account['generation'], $_SESSION['user_generation'])
            || !is_int($signedIn)
            || !is_int($lastSeen)
            || $signedIn <= 0
            || $lastSeen <= 0
            || $signedIn > $now
            || $lastSeen > $now
            || $signedIn <= $now - $this->config->int('SESSION_ABSOLUTE_SECONDS', 43200)
            || $lastSeen <= $now - $this->config->int('SESSION_IDLE_SECONDS', 1800)
        ) {
            $_SESSION = [];
            session_regenerate_id(true);
            return;
        }

        $_SESSION['last_seen_at'] = $now;
    }

    public function requireUser(string $user): void
    {
        $this->validate();
        if (($_SESSION['user'] ?? null) !== $user) {
            throw new ServiceError('AUTHENTICATION_REQUIRED', 'Sign in again.', [], 401);
        }
    }
}
