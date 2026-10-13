<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use Nyholm\Psr7\{Response, ServerRequest};
use PHPUnit\Framework\TestCase;
use YouTubeMusicMcp\Auth\{OAuth, User};
use YouTubeMusicMcp\ServiceError;

final class OAuthTest extends TestCase
{
    use Fixture;

    private string $dir;

    public function testCodePkceExchangeAudienceAndRevocation(): void
    {
        [$db, $oauth, $client] = $this->environment();
        [$code, $verifier] = $this->code($oauth, $client);
        $args = [
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'redirect_uri' => 'https://chatgpt.com/callback',
            'code' => $code,
            'code_verifier' => $verifier
        ];
        $request = (new ServerRequest('POST', 'https://music.example.test/oauth/token'))->withParsedBody($args);
        $r = $oauth->server->respondToAccessTokenRequest($request, new Response());
        $tokens = json_decode((string)$r->getBody(), true);
        $bearer = (new ServerRequest('POST', 'https://music.example.test/mcp'))->withHeader(
            'Authorization',
            'Bearer ' . $tokens['access_token']
        );
        self::assertSame('u1', $oauth->authenticate($bearer)['id']);
        self::assertContains('playlists.write', $oauth->authenticate($bearer)['scopes']);
        $db->execute('UPDATE oauth_tokens SET revoked=1 WHERE user_id=?', ['u1']);
        $this->expectException(ServiceError::class);
        $oauth->authenticate($bearer);
    }

    private function environment(): array
    {
        $db = $this->database();
        $this->user($db);
        $oauth = new OAuth(
            $db,
            $this->config(['STORAGE_PATH' => $this->dir, 'OAUTH_REDIRECT_URIS' => 'https://chatgpt.com/callback'])
        );
        $client = $oauth->register(['redirect_uris' => ['https://chatgpt.com/callback']]);
        return [$db, $oauth, $client];
    }

    private function code(OAuth $oauth, array $client): array
    {
        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $q = [
            'client_id' => $client['client_id'],
            'redirect_uri' => 'https://chatgpt.com/callback',
            'response_type' => 'code',
            'scope' => 'playlists.read playlists.write',
            'state' => 'state-test',
            'resource' => 'https://music.example.test/mcp',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256'
        ];
        $auth = $oauth->validateAuthorization(
            (new ServerRequest('GET', 'https://music.example.test/oauth/authorize'))->withQueryParams($q)
        );
        $auth->setUser(new User('u1'));
        $auth->setAuthorizationApproved(true);
        $r = $oauth->server->completeAuthorizationRequest($auth, new Response());
        parse_str(parse_url($r->getHeaderLine('Location'), PHP_URL_QUERY), $out);
        self::assertSame('state-test', $out['state']);
        return [$out['code'], $verifier];
    }

    public function testCodeCannotBeReplayed(): void
    {
        [$db, $oauth, $client] = $this->environment();
        [$code, $verifier] = $this->code($oauth, $client);
        $r = (new ServerRequest('POST', 'https://music.example.test/oauth/token'))->withParsedBody(
            [
                'grant_type' => 'authorization_code',
                'client_id' => $client['client_id'],
                'redirect_uri' => 'https://chatgpt.com/callback',
                'code' => $code,
                'code_verifier' => $verifier
            ]
        );
        $oauth->server->respondToAccessTokenRequest($r, new Response());
        $this->expectException(\League\OAuth2\Server\Exception\OAuthServerException::class);
        $oauth->server->respondToAccessTokenRequest($r, new Response());
    }

    public function testWrongPkceRejected(): void
    {
        [$db, $oauth, $client] = $this->environment();
        [$code, $verifier] = $this->code($oauth, $client);
        $r = (new ServerRequest('POST', 'https://music.example.test/oauth/token'))->withParsedBody(
            [
                'grant_type' => 'authorization_code',
                'client_id' => $client['client_id'],
                'redirect_uri' => 'https://chatgpt.com/callback',
                'code' => $code,
                'code_verifier' => str_repeat('b', 64)
            ]
        );
        $this->expectException(\League\OAuth2\Server\Exception\OAuthServerException::class);
        $oauth->server->respondToAccessTokenRequest($r, new Response());
    }

    public function testUnapprovedRedirectRejected(): void
    {
        [$db, $oauth] = $this->environment();
        $this->expectException(ServiceError::class);
        $oauth->register(['redirect_uris' => ['https://attacker.test/callback']]);
    }

    public function testRefreshRotatesAndCannotBeReplayed(): void
    {
        [$db, $oauth, $client] = $this->environment();
        [$code, $verifier] = $this->code($oauth, $client);
        $r = (new ServerRequest('POST', 'https://music.example.test/oauth/token'))->withParsedBody(
            [
                'grant_type' => 'authorization_code',
                'client_id' => $client['client_id'],
                'redirect_uri' => 'https://chatgpt.com/callback',
                'code' => $code,
                'code_verifier' => $verifier
            ]
        );
        $tokens = json_decode((string)$oauth->server->respondToAccessTokenRequest($r, new Response())->getBody(), true);
        $refresh = $r->withParsedBody(
            [
                'grant_type' => 'refresh_token',
                'client_id' => $client['client_id'],
                'refresh_token' => $tokens['refresh_token']
            ]
        );
        $next = json_decode(
            (string)$oauth->server->respondToAccessTokenRequest($refresh, new Response())->getBody(),
            true
        );
        self::assertNotSame($tokens['refresh_token'], $next['refresh_token']);
        $bearer = (new ServerRequest('POST', 'https://music.example.test/mcp'))->withHeader(
            'Authorization',
            'Bearer ' . $next['access_token']
        );
        self::assertContains('playlists.write', $oauth->authenticate($bearer)['scopes']);
        $this->expectException(\League\OAuth2\Server\Exception\OAuthServerException::class);
        $oauth->server->respondToAccessTokenRequest($refresh, new Response());
    }

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $private);
        file_put_contents($this->dir . '/oauth-private.pem', $private);
        file_put_contents($this->dir . '/oauth-public.pem', openssl_pkey_get_details($key)['key']);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }
}
