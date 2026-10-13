<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use YouTubeMusicMcp\Http\Client;
use YouTubeMusicMcp\Music\{Api, Bootstrap};
use YouTubeMusicMcp\ServiceError;

test('music bootstrap parses only public json fields without evaluating javascript', function (): void {
    $html = '<script>ytcfg.set({"INNERTUBE_CLIENT_VERSION":"1.20260820.01.00","VISITOR_DATA":"visitor\u003d","secret":"discard"}); window.evil();</script>';
    $this->assertSame(['web_client' => ['clientVersion' => '1.20260820.01.00', 'visitorData' => 'visitor=']], Bootstrap::parse($html));
    $this->assertSame(['web_client' => []], Bootstrap::parse('<html>Consent required</html>'));
});

test('music requests use actual web metadata consistently and never send oauth to public bootstrap', function (): void {
    $http = new class extends Client {
        public array $calls = [];
        public function request(string $method, string $url, array $headers = [], ?string $body = null): array {
            $this->calls[] = [$method, $url, $headers, $body ? json_decode($body, true) : null];
            return $method === 'GET'
                ? ['status' => 200, 'data' => ['web_client' => ['clientVersion' => '1.20260820.01.00', 'visitorData' => 'public-visitor']]]
                : ['status' => 200, 'data' => ['actions' => [['activeAccountHeaderRenderer' => ['accountName' => ['simpleText' => 'Fixture'], 'channelHandle' => ['simpleText' => '@fixture']]]]]];
        }
    };
    $api = new Api($this->config(), $http);
    $api->call('get_music_account', [], 'dummy-token-one');
    $api->call('get_music_account', [], 'dummy-token-two');
    $this->assertCount(3, $http->calls); // Public profile reused only in this API instance.
    $this->assertSame('https://music.youtube.com/', $http->calls[0][1]);
    $this->assertStringNotContainsString('dummy-token', implode(' ', $http->calls[0][2]));
    $this->assertSame('1.20260820.01.00', $http->calls[1][3]['context']['client']['clientVersion']);
    $this->assertSame('public-visitor', $http->calls[1][3]['context']['client']['visitorData']);
    $this->assertContains('X-YouTube-Client-Name: 67', $http->calls[1][2]);
    $this->assertContains('X-YouTube-Client-Version: 1.20260820.01.00', $http->calls[1][2]);
    $this->assertContains('X-Goog-Visitor-Id: public-visitor', $http->calls[1][2]);
    $this->assertContains('Authorization: Bearer dummy-token-two', $http->calls[2][2]);
});

test('missing or unsafe bootstrap metadata prevents account mutation dispatch', function (): void {
    foreach ([[], ['clientVersion' => '1.20260820.01.00', 'visitorData' => "visitor\r\nInjected: header"],
        ['clientVersion' => 'invalid', 'visitorData' => 'visitor']] as $profile) {
        $http = new class($profile) extends Client {
            public int $calls = 0;
            public function __construct(private array $profile) {}
            public function request(string $method, string $url, array $headers = [], ?string $body = null): array {
                $this->calls++;
                return ['status' => 200, 'data' => ['web_client' => $this->profile]];
            }
        };
        try {
            (new Api($this->config(), $http))->call('set_music_playlist_library_status', ['playlist_id' => 'PLfixture', 'in_library' => true], 'dummy-token');
            $this->fail();
        } catch (ServiceError $error) { $this->assertSame('MUSIC_BOOTSTRAP_UNAVAILABLE', $error->reason); }
        $this->assertSame(1, $http->calls);
    }
});

test('bootstrap timeout remains a preflight failure rather than an uncertain mutation', function (): void {
    $http = new class extends Client {
        public function request(string $method, string $url, array $headers = [], ?string $body = null): array {
            throw new ServiceError('UPSTREAM_UNCERTAIN', 'timeout');
        }
    };
    try { (new Api($this->config(), $http))->call('set_music_playlist_library_status', ['playlist_id' => 'PLfixture', 'in_library' => true], 'dummy-token'); $this->fail(); }
    catch (ServiceError $error) { $this->assertSame('MUSIC_BOOTSTRAP_UNAVAILABLE', $error->reason); }
});

test('client version override still requires public visitor context', function (): void {
    $http = new class extends Client {
        public function request(string $method, string $url, array $headers = [], ?string $body = null): array {
            return ['status' => 200, 'data' => ['web_client' => ['clientVersion' => '1.20260820.01.00', 'visitorData' => 'visitor']]];
        }
    };
    $profile = (new Bootstrap($http))->load($this->config(['MUSIC_CLIENT_VERSION' => '1.20260901.01.00']));
    $this->assertSame('1.20260901.01.00', $profile['clientVersion']);
    $this->assertSame('visitor', $profile['visitorData']);
});
