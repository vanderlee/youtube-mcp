<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Integration;

use DateTimeImmutable;
use League\OAuth2\Server\CryptKey;
use Nyholm\Psr7\{Response, ServerRequest};
use YouTubeMcp\{App, Config, ServiceError};
use YouTubeMcp\Auth\{BrowserSession, Client as OAuthClient, Google, OAuth, Scope, User, Vault};
use YouTubeMcp\Http\Client;
use YouTubeMcp\Operations\Receipts;
use YouTubeMcp\Playlist\Service;
use YouTubeMcp\Quota\Budget;
use YouTubeMcp\Storage\{Database, Lock};
use YouTubeMcp\YouTube\Api;

trait SecurityTestHelpers
{
    private string $dir;
    private Database $db;
    private Config $cfg;
    private Lock $locks;
    private OAuth $oauth;
    private string $privateKey;

    private function bearer(array $scopes = ['likes.write']): ServerRequest
    {
        $token = $this->oauth->repository->getNewToken(new OAuthClient('test', 'test', ['https://chatgpt.com/callback']),
            array_map(fn($scope) => new Scope($scope), $scopes), 'u1');
        $token->setIdentifier(bin2hex(random_bytes(16)));
        $token->setExpiryDateTime(new DateTimeImmutable('+1 hour'));
        $token->setPrivateKey(new CryptKey($this->privateKey, null, false));
        $this->oauth->repository->persistNewAccessToken($token);

        return new ServerRequest('POST', $this->cfg->url() . '/mcp', ['Authorization' => 'Bearer ' . $token->toString()]);
    }

    private function google(?Client $http = null): Google
    {
        return new Google($this->cfg, $this->db, new Vault($this->cfg->get('APP_KEY')), $http ?? new Client(), $this->locks);
    }

    private function connect(Google $google, string $channel): void
    {
        $google->connect('u1', ['scope' => Google::YOUTUBE_SCOPE, 'refresh_token' => 'dummy-refresh',
            'access_token' => 'dummy-access', 'expires_at' => time() + 3600],
            ['id' => $channel, 'snippet' => ['title' => $channel]]);
    }

    private function tokens(): array
    {
        $client = $this->oauth->register(['redirect_uris' => ['https://chatgpt.com/callback']]);
        $verifier = str_repeat('a', 64);
        $query = ['client_id' => $client['client_id'], 'redirect_uri' => 'https://chatgpt.com/callback',
            'response_type' => 'code', 'scope' => 'playlists.read playlists.write', 'resource' => $this->cfg->url() . '/mcp',
            'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
        $authorization = $this->oauth->validateAuthorization((new ServerRequest('GET', $this->cfg->url() . '/oauth/authorize'))->withQueryParams($query));
        $authorization->setUser(new User('u1'));
        $authorization->setAuthorizationApproved(true);
        $response = $this->oauth->server->completeAuthorizationRequest($authorization, new Response());
        parse_str(parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $redirect);
        $request = (new ServerRequest('POST', $this->cfg->url() . '/oauth/token'))->withParsedBody([
            'grant_type' => 'authorization_code', 'client_id' => $client['client_id'],
            'redirect_uri' => 'https://chatgpt.com/callback', 'code' => $redirect['code'], 'code_verifier' => $verifier,
        ]);
        $tokens = json_decode((string)$this->oauth->exchange($request)->getBody(), true);

        return [$client, $tokens, $request];
    }
}

uses(SecurityTestHelpers::class);

test('refresh issuance excludes a concurrent revocation and later revocation invalidates every new token', function (): void {
    [$client, $tokens, $request] = $this->tokens();
    $other = new Database($this->dir . '/service.sqlite');
    $other->pdo->exec('PRAGMA busy_timeout=0');
    $blocked = false;
    $this->oauth->server->getListenerRegistry()->subscribeOnceTo('access_token.issued', function () use ($other, &$blocked) {
        try {
            $other->execute('UPDATE oauth_tokens SET revoked=1 WHERE user_id=?', ['u1']);
        } catch (\PDOException $error) {
            $blocked = Database::errorDetails($error)['database_reason'] === 'database_locked';
        }
    });
    $refresh = $request->withParsedBody(['grant_type' => 'refresh_token', 'client_id' => $client['client_id'], 'refresh_token' => $tokens['refresh_token']]);
    $next = json_decode((string)$this->oauth->exchange($refresh)->getBody(), true);
    $this->assertTrue($blocked);
    $other->execute('UPDATE oauth_tokens SET revoked=1 WHERE user_id=?', ['u1']);
    $this->assertSame(0, (int)$other->one('SELECT COUNT(*) AS n FROM oauth_tokens WHERE revoked=0')['n']);
    $this->expectException(\League\OAuth2\Server\Exception\OAuthServerException::class);
    $this->oauth->exchange($refresh->withParsedBody(['grant_type' => 'refresh_token', 'client_id' => $client['client_id'], 'refresh_token' => $next['refresh_token']]));
});

test('failed token issuance rolls back rotation and leaves the previous refresh usable', function (): void {
    [$client, $tokens, $request] = $this->tokens();
    $count = count($this->db->all('SELECT id FROM oauth_tokens'));
    $this->oauth->server->getListenerRegistry()->subscribeOnceTo('access_token.issued', fn() => throw new \RuntimeException('Injected interruption'));
    $refresh = $request->withParsedBody(['grant_type' => 'refresh_token', 'client_id' => $client['client_id'], 'refresh_token' => $tokens['refresh_token']]);
    try {
        $this->oauth->exchange($refresh);
        $this->fail('Expected injected failure.');
    } catch (\RuntimeException $error) {
        $this->assertSame('Injected interruption', $error->getMessage());
    }
    $this->assertCount($count, $this->db->all('SELECT id FROM oauth_tokens'));
    $this->assertSame(200, $this->oauth->exchange($refresh)->getStatusCode());
});

test('a request authenticated before reconnect cannot dispatch work through the replacement connection', function (): void {
    $google = $this->google();
    $this->connect($google, 'channel-A');
    $identity = $this->oauth->authenticate($this->bearer());
    $this->connect($google, 'channel-B');
    $called = false;
    try {
        $this->oauth->runAuthenticated($identity, function () use (&$called) { $called = true; });
        $this->fail('Expected revoked identity rejection.');
    } catch (ServiceError $error) {
        $this->assertSame('AUTHENTICATION_REQUIRED', $error->reason);
    }
    $this->assertFalse($called);
});

test('authenticated work holds the same account exclusion as reconnect and revoke', function (): void {
    $identity = $this->oauth->authenticate($this->bearer());
    $other = new Lock($this->dir . '/locks');
    $this->oauth->runAuthenticated($identity, function () use ($other) {
        try {
            $other->run('account:u1', fn() => $this->fail('Account exclusion was bypassed.'));
            $this->fail('Expected busy account.');
        } catch (ServiceError $error) {
            $this->assertSame('OPERATION_BUSY', $error->reason);
        }
    });
});

test('account deletion and recreation never revive another browser session', function (): void {
    $app = new App($this->cfg);
    $app->handle(new ServerRequest('GET', $this->cfg->url() . '/'));
    $browser = new BrowserSession($this->db, $this->cfg);
    $browser->signIn('u1');
    $stale = $_SESSION;
    $response = $app->handle(new ServerRequest('POST', $this->cfg->url() . '/account/delete',
        ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['csrf' => $_SESSION['csrf']])));
    $this->assertSame(303, $response->getStatusCode());
    $this->assertNull($this->db->one('SELECT id FROM users WHERE id=?', ['u1']));
    $_SESSION = $stale;
    $app->handle(new ServerRequest('GET', $this->cfg->url() . '/'));
    $this->assertArrayNotHasKey('user', $_SESSION);
    $this->user($this->db);
    $browser->signIn('u1');
    $this->assertNotSame($stale['user_generation'], $_SESSION['user_generation']);
    $_SESSION = $stale;
    $app->handle(new ServerRequest('GET', $this->cfg->url() . '/'));
    $this->assertArrayNotHasKey('user', $_SESSION);
});

test('browser authentication enforces idle and absolute expiry and rejects legacy sessions', function (): void {
    $app = new App($this->cfg);
    $app->handle(new ServerRequest('GET', $this->cfg->url() . '/'));
    $browser = new BrowserSession($this->db, $this->cfg);
    foreach (['idle', 'absolute', 'legacy'] as $case) {
        $browser->signIn('u1');
        if ($case === 'idle') {
            $_SESSION['last_seen_at'] = time() - 1800;
        } elseif ($case === 'absolute') {
            $_SESSION['signed_in_at'] = time() - 43200;
        } else {
            unset($_SESSION['user_generation']);
        }
        $browser->validate();
        $this->assertArrayNotHasKey('user', $_SESSION);
    }
});

test('receipts require the originating permission and current connection generation', function (): void {
    $google = $this->google();
    $this->connect($google, 'channel-A');
    $generation = $google->connection('u1')['generation'];
    $receipts = new Receipts($this->db, $this->locks);
    $receipt = $receipts->run('u1', $generation, 'update_comment', ['operation_key' => 'comment-review'],
        fn($step) => $step('comment', fn() => ['id' => 'comment-id', 'snippet' => ['textOriginal' => 'private text']]));
    $service = new Service($google, new Api(new Client(), new Budget($this->db, $this->cfg), $this->db, $google),
        new Budget($this->db, $this->cfg), $receipts, $this->locks);
    try {
        $service->execute('u1', ['playlists.read'], 'get_operation', ['operation_id' => $receipt['operation_id']]);
        $this->fail('Playlist scope must not disclose comment details.');
    } catch (ServiceError $error) {
        $this->assertSame('INSUFFICIENT_SERVICE_SCOPE', $error->reason);
    }
    foreach (['comments.read', 'comments.write'] as $scope) {
        $this->assertSame($receipt, $service->execute('u1', [$scope], 'get_operation', ['operation_id' => $receipt['operation_id']]));
    }
    $this->connect($google, 'channel-B');
    $this->expectException(ServiceError::class);
    $this->expectExceptionMessage('Operation was not found for this account connection.');
    $service->execute('u1', ['comments.read'], 'get_operation', ['operation_id' => $receipt['operation_id']]);
});

test('an old response cannot populate or be served from a replacement connection cache', function (): void {
    $google = $this->google();
    $this->connect($google, 'channel-A');
    $http = new class extends Client {
        public $duringResponse;
        public int $calls = 0;

        public function request(string $method, string $url, array $headers = [], ?string $body = null): array
        {
            $this->calls++;
            if ($this->duringResponse !== null) {
                $callback = $this->duringResponse;
                $this->duringResponse = null;
                $callback();
            }

            return ['status' => 200, 'data' => ['items' => [['id' => 'response-' . $this->calls]]]];
        }
    };
    $api = new Api($http, new Budget($this->db, $this->cfg), $this->db, $google);
    $http->duringResponse = fn() => $this->connect($google, 'channel-B');
    try {
        $api->call('u1', 'playlists.list', ['mine' => 'true'], ttl: 30);
        $this->fail('Old connection response must be rejected.');
    } catch (ServiceError $error) {
        $this->assertSame('GOOGLE_CONNECTION_CHANGED', $error->reason);
    }
    $this->assertSame([], $this->db->all('SELECT * FROM api_cache'));
    $current = $api->call('u1', 'playlists.list', ['mine' => 'true'], ttl: 30);
    $this->assertSame('response-2', $current['items'][0]['id']);
    $this->assertSame($current, $api->call('u1', 'playlists.list', ['mine' => 'true'], ttl: 30));
    $this->assertSame(2, $http->calls);
    $http->duringResponse = fn() => $this->connect($google, 'channel-C');
    try {
        $api->call('u1', 'videos.rate', ['id' => 'abcdefghijk', 'rating' => 'like']);
        $this->fail('Dispatched mutation must retain uncertain semantics after a connection change.');
    } catch (ServiceError $error) {
        $this->assertSame('UPSTREAM_UNCERTAIN', $error->reason);
    }
});

test('anonymous registrations reuse one public client per callback and cannot create confidential clients', function (): void {
    $app = new App($this->cfg);
    $ids = [];
    for ($i = 0; $i < 205; $i++) {
        $request = new ServerRequest('POST', $this->cfg->url() . '/oauth/register', ['Content-Type' => 'application/json'],
            json_encode(['redirect_uris' => ['https://chatgpt.com/callback'], 'client_name' => 'arbitrary-' . $i]));
        $response = $app->handle($request);
        $this->assertSame(201, $response->getStatusCode());
        $ids[] = json_decode((string)$response->getBody(), true)['client_id'];
    }
    $this->assertCount(1, array_unique($ids));
    $this->assertSame(1, (int)$this->db->one('SELECT COUNT(*) AS n FROM oauth_clients')['n']);
    $confidential = $app->handle(new ServerRequest('POST', $this->cfg->url() . '/oauth/register', ['Content-Type' => 'application/json'],
        json_encode(['redirect_uris' => ['https://chatgpt.com/callback'], 'token_endpoint_auth_method' => 'client_secret_post'])));
    $this->assertSame(400, $confidential->getStatusCode());
    $static = $this->oauth->register(['redirect_uris' => ['https://chatgpt.com/callback'], 'token_endpoint_auth_method' => 'client_secret_post']);
    $this->assertArrayHasKey('client_secret', $static);
});

test('lock storage stays bounded and independent owners cannot bypass held locks', function (): void {
    for ($i = 0; $i < 1000; $i++) {
        $this->locks->run('operation:u1:unique-' . $i, fn() => null);
    }
    $this->assertLessThanOrEqual(256, count(glob($this->dir . '/locks/slot-operation-*.lock')));
    $other = new Lock($this->dir . '/locks');
    $this->locks->run('account:u1', function () use ($other) {
        $this->assertSame('nested', $this->locks->run('account:u1', fn() => 'nested'));
        try {
            $other->run('account:u1', fn() => $this->fail('Independent owner entered held lock.'));
            $this->fail('Expected lock exclusion.');
        } catch (ServiceError $error) {
            $this->assertSame('OPERATION_BUSY', $error->reason);
        }
    });
    $this->assertSame('released', $other->run('account:u1', fn() => 'released'));
});

beforeEach(function (): void {
    $this->dir = $this->tempDir();
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $private);
    $this->privateKey = $private;
    file_put_contents($this->dir . '/oauth-private.pem', $this->privateKey);
    file_put_contents($this->dir . '/oauth-public.pem', openssl_pkey_get_details($key)['key']);
    chmod($this->dir . '/oauth-private.pem', 0600);
    chmod($this->dir . '/oauth-public.pem', 0600);
    $this->cfg = $this->config(['STORAGE_PATH' => $this->dir, 'OAUTH_REDIRECT_URIS' => 'https://chatgpt.com/callback']);
    $this->db = new Database($this->dir . '/service.sqlite');
    $this->db->migrate(dirname(__DIR__, 2));
    $this->user($this->db);
    $this->locks = new Lock($this->dir . '/locks');
    $this->oauth = new OAuth($this->db, $this->cfg, $this->locks);
});

afterEach(function (): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_write_close();
    }
    unset($this->oauth, $this->db, $this->locks);
    gc_collect_cycles();
    $this->removeDir($this->dir);
});
