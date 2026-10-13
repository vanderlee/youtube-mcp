<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use YouTubeMusicMcp\Auth\{Google, MusicTokenDiagnostics};
use YouTubeMusicMcp\Http\Client;

test('music token introspection reports only comparisons and posts secrets outside URLs', function (): void {
    $http = new class extends Client {
        public array $calls = [];
        public array $response = ['status' => 200, 'data' => ['azp' => 'dummy-client', 'aud' => 'dummy-client',
            'scope' => Google::MUSIC_SCOPE, 'expires_in' => '3600', 'email' => 'private-email', 'sub' => 'private-id']];
        public function request(string $method, string $url, array $headers = [], ?string $body = null): array {
            $this->calls[] = [$method, $url, $body];
            return $this->response;
        }
    };
    $probe = new MusicTokenDiagnostics($this->config(['MUSIC_OAUTH_CLIENT_ID' => 'dummy-client']), $http);
    $report = $probe->inspect('private-token');
    $this->assertSame(['checked' => true, 'issued_to_music_client_matches' => true,
        'audience_music_client_matches' => true, 'music_scope_present' => true, 'token_unexpired' => true], $report);
    $this->assertSame('POST', $http->calls[0][0]);
    $this->assertSame('https://oauth2.googleapis.com/tokeninfo', $http->calls[0][1]);
    $this->assertSame('access_token=private-token', $http->calls[0][2]);
    $this->assertStringNotContainsString('private', json_encode($report));
    $http->response['data'] = ['issued_to' => 'wrong-client', 'audience' => 'wrong-client', 'scope' => 'other', 'expires_in' => 0];
    $this->assertSame(['checked' => true, 'issued_to_music_client_matches' => false,
        'audience_music_client_matches' => false, 'music_scope_present' => false, 'token_unexpired' => false], $probe->inspect('private-token'));
    $http->response['data'] = [];
    $this->assertNull($probe->inspect('private-token')['issued_to_music_client_matches']);
    $http->response = ['status' => 400, 'data' => ['error_description' => 'private-token']];
    $this->assertSame(['checked' => false, 'upstream_http_status' => 400], $probe->inspect('private-token'));
});
