<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use Nyholm\Psr7\ServerRequest;
use YouTubeMusicMcp\{App};
use YouTubeMusicMcp\Auth\{Client, OAuthRepository, Scope};
use YouTubeMusicMcp\Storage\Database;

trait McpTestHelpers
{
    private string $dir;
    private App $app;
    private string $token;

    private function rpc(array $payload, ?string $session = null): \Psr\Http\Message\ResponseInterface
    {
        $headers = [
            'Host' => 'music.example.test',
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream'
        ];
        if ($session) {
            $headers['Mcp-Session-Id'] = $session;
            $headers['Mcp-Protocol-Version'] = '2025-06-18';
        }
        return $this->app->handle(
            new ServerRequest('POST', 'https://music.example.test/mcp', $headers, json_encode($payload))
        );
    }
}

uses(McpTestHelpers::class);

test('sdk handshake discovery and local usage tool', function (): void {
    $r = $this->rpc(
        [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '1']
            ]
        ]
    );
    $this->assertSame(200, $r->getStatusCode(), (string)$r->getBody());
    $session = $r->getHeaderLine('Mcp-Session-Id');
    $this->assertNotEmpty($session);
    $this->rpc(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $session);
    $list = $this->rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $session);
    $this->assertSame(200, $list->getStatusCode(), (string)$list->getBody());
    $this->assertStringContainsString('add_tracks', (string)$list->getBody());
    $this->assertStringNotContainsString('search_music', (string)$list->getBody());
    $this->assertStringNotContainsString('history.write', (string)$list->getBody());
    $usage = $this->rpc(
        [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'get_usage_status', 'arguments' => new \stdClass()]
        ],
        $session
    );
    $this->assertSame(200, $usage->getStatusCode(), (string)$usage->getBody());
    $this->assertStringContainsString('quota_enforced_by', (string)$usage->getBody());
    $result = json_decode((string)$usage->getBody(), true, 512, JSON_THROW_ON_ERROR)['result'];
    $this->assertSame('youtube', $result['structuredContent']['quota_enforced_by']);
    $this->assertLessThan(80, strlen($result['content'][0]['text']));
    $this->assertStringNotContainsString('quota_enforced_by', $result['content'][0]['text']);
});

test('unauthorized endpoint returns discovery challenge', function (): void {
    $r = $this->app->handle(
        new ServerRequest('POST', 'https://music.example.test/mcp', ['Host' => 'music.example.test'])
    );
    $this->assertSame(401, $r->getStatusCode());
    $this->assertStringContainsString('oauth-protected-resource/mcp', $r->getHeaderLine('WWW-Authenticate'));
});

test('retired music authentication routes return not found', function (): void {
    foreach (['/music/connect', '/music/poll', '/music/disconnect', '/music/browser', '/music/browser/disconnect'] as $path) {
        $response = $this->app->handle(new ServerRequest('GET', 'https://music.example.test' . $path));
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('NOT_FOUND', (string)$response->getBody());
    }
});

test('browser media staging requires csrf and stores user bound assets without echoing file contents', function (): void {
    // Start an isolated browser session through the normal HTTP path.
    $this->app->handle(new ServerRequest('GET', 'https://music.example.test/'));
    $_SESSION['user'] = 'u1';
    $csrf = $_SESSION['csrf'];
    $db = new Database($this->dir . '/service.sqlite');
    $db->execute('INSERT INTO google_connections VALUES (?,?,?,?,?,?)', ['u1', 'channel-u1', 'Fixture', 'unused', 'scope', 'generation']);
    try {
        $text = "1\n00:00:01,000 --> 00:00:02,000\nDo not echo this private caption\n";
        $file = new \Nyholm\Psr7\UploadedFile(\Nyholm\Psr7\Stream::create($text), strlen($text), UPLOAD_ERR_OK, 'fixture.srt');
        $request = (new ServerRequest('POST', 'https://music.example.test/media', ['Content-Type' => 'multipart/form-data; boundary=fixture']))
            ->withUploadedFiles(['media' => $file])->withParsedBody(['csrf' => 'wrong']);
        $rejected = $this->app->handle($request);
        $this->assertSame(403, $rejected->getStatusCode());
        $this->assertSame([], $db->all('SELECT id FROM media_assets'));
        $accepted = $this->app->handle($request->withParsedBody(['csrf' => $csrf]));
        $this->assertSame(200, $accepted->getStatusCode(), (string)$accepted->getBody());
        $row = $db->one('SELECT * FROM media_assets WHERE user_id=?', ['u1']);
        $this->assertSame('generation', $row['generation']);
        $this->assertStringContainsString($row['id'], (string)$accepted->getBody());
        $this->assertStringNotContainsString('Do not echo', (string)$accepted->getBody());
    } finally {
        $_SESSION = [];
        session_write_close();
        unset($db);
    }
});

test('preference tools advertise separate scopes', function (): void {
    $tools = array_column(\YouTubeMusicMcp\Mcp\Tools::definitions(), null, 'name');
    foreach (
        [
            'list_liked_tracks' => 'likes.read',
            'get_track_ratings' => 'likes.read',
            'rate_track' => 'likes.write',
            'list_subscriptions' => 'subscriptions.read',
            'subscribe_artist' => 'subscriptions.write',
            'unsubscribe_artist' => 'subscriptions.write'
        ] as $name => $scope
    ) {
        $this->assertSame($scope, \YouTubeMusicMcp\Playlist\Service::scope($name));
        $this->assertArrayHasKey($name, $tools);
        $this->assertStringContainsString($scope, json_encode($tools[$name]));
    }
    $repo = new OAuthRepository($this->database(), 'https://music.example.test');
    foreach (Scope::SUPPORTED as $scope) {
        $this->assertNotNull($repo->getScopeEntityByIdentifier($scope));
    }
    $this->assertNull($repo->getScopeEntityByIdentifier('unknown.write'));
});

test('efficiency options are advertised without changing write permissions', function (): void {
    $tools = array_column(\YouTubeMusicMcp\Mcp\Tools::definitions(), null, 'name');
    $this->assertArrayHasKey('video_ids', $tools['create_playlist']->inputSchema['properties']);
    $this->assertArrayHasKey('include_details', $tools['search_tracks']->inputSchema['properties']);
    $this->assertArrayHasKey('response_detail', $tools['get_operation']->inputSchema['properties']);
    $this->assertArrayNotHasKey('response_detail', $tools['create_playlist']->inputSchema['properties']);
    $this->assertSame(['playlists.write'], $tools['create_playlist']->meta['securitySchemes'][0]['scopes']);
});

test('full receipt is available on demand and not duplicated in text', function (): void {
    $receipt = [
        'operation_id' => 'receipt-test',
        'status' => 'partial',
        'tool' => 'add_tracks',
        'completed' => [
            'add:0' => [
                'id' => 'item1',
                'snippet' => ['description' => 'raw detail', 'resourceId' => ['videoId' => 'aaaaaaaaaaa']]
            ]
        ],
        'error' => ['code' => 'YOUTUBE_QUOTA_EXHAUSTED']
    ];
    $db = new Database($this->dir . '/service.sqlite');
    $db->execute(
        'INSERT INTO operations VALUES (?,?,?,?,?,?,?,?,?,?)',
        [
            'receipt-test',
            'u1',
            'generation',
            'add_tracks',
            'receipt-key',
            'hash',
            'partial',
            json_encode($receipt),
            time(),
            time()
        ]
    );
    unset($db);
    $init = $this->rpc(
        [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '1']
            ]
        ]
    );
    $session = $init->getHeaderLine('Mcp-Session-Id');
    $this->rpc(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $session);
    foreach (['compact', 'full'] as $n => $detail) {
        $r = $this->rpc(
            [
                'jsonrpc' => '2.0',
                'id' => $n + 2,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_operation',
                    'arguments' => ['operation_id' => 'receipt-test', 'response_detail' => $detail]
                ]
            ],
            $session
        );
        $result = json_decode((string)$r->getBody(), true, 512, JSON_THROW_ON_ERROR)['result'];
        $this->assertTrue($result['isError']);
        $this->assertStringNotContainsString('raw detail', $result['content'][0]['text']);
        if ($detail === 'full') {
            $this->assertSame($receipt, $result['structuredContent']);
        } else {
            $this->assertArrayNotHasKey('completed', $result['structuredContent']);
        }
    }
});

test('duplicate host headers do not block authenticated initialization', function (): void {
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1']
        ]
    ];
    $headers = [
        'Host' => ['music.example.test', 'music.example.test'],
        'Authorization' => 'Bearer ' . $this->token,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream'
    ];
    $r = $this->app->handle(
        new ServerRequest('POST', 'https://music.example.test/mcp', $headers, json_encode($payload))
    );
    $this->assertSame(200, $r->getStatusCode(), (string)$r->getBody());
    $invalid = $this->app->handle(
        new ServerRequest('POST', 'https://attacker.test/mcp', $headers, json_encode($payload))
    );
    $this->assertSame(400, $invalid->getStatusCode());
    $this->assertStringContainsString('INVALID_HOST', (string)$invalid->getBody());
});

test('browser policies permit google and chat gpt form redirects', function (): void {
    $r = $this->app->handle(new ServerRequest('GET', 'https://music.example.test/health/live'));
    $this->assertSame('same-origin', $r->getHeaderLine('Referrer-Policy'));
    $this->assertStringContainsString(
        "form-action 'self' https://accounts.google.com https://chatgpt.com;",
        $r->getHeaderLine('Content-Security-Policy')
    );
    $this->assertStringContainsString("frame-ancestors 'none'", $r->getHeaderLine('Content-Security-Policy'));
});

test('same origin allowed but null and foreign origins rejected', function (): void {
    foreach (
        [
            'https://music.example.test' => 200,
            'null' => 403,
            'https://attacker.test' => 403
        ] as $origin => $status
    ) {
        $r = $this->app->handle(
            (new ServerRequest('GET', 'https://music.example.test/health/live'))->withHeader('Origin', $origin)
        );
        $this->assertSame($status, $r->getStatusCode());
    }
});

beforeEach(function (): void {
    $this->dir = $this->tempDir();
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $private);
    file_put_contents($this->dir . '/oauth-private.pem', $private);
    file_put_contents($this->dir . '/oauth-public.pem', openssl_pkey_get_details($key)['key']);
    $db = new Database($this->dir . '/service.sqlite');
    $db->migrate(dirname(__DIR__));
    $this->user($db);
    $repo = new OAuthRepository($db, 'https://music.example.test');
    $token = $repo->getNewToken(new Client('test', 'test', ['https://chatgpt.com/callback']),
        [new Scope('playlists.read')],
        'u1');
    $token->setIdentifier('test-token');
    $token->setExpiryDateTime(new \DateTimeImmutable('+1 hour'));
    $token->setPrivateKey(new \League\OAuth2\Server\CryptKey($private, null, false));
    $repo->persistNewAccessToken($token);
    $this->token = $token->toString();
    $this->app = new App($this->config(['STORAGE_PATH' => $this->dir]));
});

afterEach(function (): void {
    unset($this->app);
    gc_collect_cycles();
    $this->removeDir($this->dir);
});
