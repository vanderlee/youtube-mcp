<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use PHPUnit\Framework\TestCase;
use YouTubeMusicMcp\Auth\{Google, Vault};
use YouTubeMusicMcp\Http\Client;
use YouTubeMusicMcp\Quota\Budget;
use YouTubeMusicMcp\ServiceError;
use YouTubeMusicMcp\Storage\Lock;

final class GoogleTest extends TestCase
{
    use Fixture;

    private string $dir;

    public function testDeletingAndRecreatingUserPreservesUsageHistory(): void
    {
        $db = $this->database();
        $cfg = $this->config(['ALLOW_REGISTRATION' => 'true']);
        $g = new Google($cfg, $db, new Vault($cfg->get('APP_KEY')), new Client(), new Lock($this->dir));
        $identity = ['subject' => 'https://accounts.google.com|example', 'email' => 'person@example.test'];
        $first = $g->signIn($identity);
        $budget = new Budget($db, $cfg);
        $budget->dispatch($first, 'videos.list');
        $db->execute('DELETE FROM users WHERE id=?', [$first]);
        $second = $g->signIn($identity);
        self::assertSame($first, $second);
        self::assertSame(1, $budget->status($second)['buckets']['general']['user_spent']);
    }

    public function testRefreshUpdatesOnlyTheConnectedUsersEncryptedTokens(): void
    {
        $db = $this->database();
        $this->user($db);
        $this->user($db, 'u2');
        $cfg = $this->config();
        $v = new Vault($cfg->get('APP_KEY'));
        foreach (['u1', 'u2'] as $u) {
            $db->execute(
                'INSERT INTO google_connections VALUES (?,?,?,?,?,?)',
                [
                    $u,
                    'channel-' . $u,
                    'name',
                    $v->encrypt(
                        ['access_token' => 'expired-' . $u, 'refresh_token' => 'refresh-' . $u, 'expires_at' => 0]
                    ),
                    Google::YOUTUBE_SCOPE,
                    'g'
                ]
            );
        }
        $http = new class extends Client {
            public function request(string $method, string $url, array $headers = [], ?string $body = null): array
            {
                parse_str($body, $p);
                return [
                    'status' => 200,
                    'data' => ['access_token' => 'new-' . $p['refresh_token'], 'expires_in' => 3600]
                ];
            }
        };
        $g = new Google($cfg, $db, $v, $http, new Lock($this->dir));
        self::assertSame('new-refresh-u1', $g->accessToken('u1'));
        self::assertSame(
            'expired-u2',
            $v->decrypt(
                $db->one('SELECT tokens FROM google_connections WHERE user_id=?', ['u2'])['tokens']
            )['access_token']
        );
        self::assertSame(
            'refresh-u1',
            $v->decrypt(
                $db->one('SELECT tokens FROM google_connections WHERE user_id=?', ['u1'])['tokens']
            )['refresh_token']
        );
    }

    public function testInviteOnlyOnboardingRejectsUnknownEmail(): void
    {
        $db = $this->database();
        $cfg = $this->config(['ALLOWED_EMAILS' => 'invited@example.test']);
        $g = new Google($cfg, $db, new Vault($cfg->get('APP_KEY')), new Client(), new Lock($this->dir));
        $this->expectException(ServiceError::class);
        $g->signIn(['subject' => 'issuer|other', 'email' => 'other@example.test']);
    }

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }
}

