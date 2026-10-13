<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use YouTubeMusicMcp\Music\{Diagnostics, Transport};
use YouTubeMusicMcp\ServiceError;

test('legacy google error reasons survive sanitization without messages or metadata', function (): void {
    $raw = ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'secret-token',
        'errors' => [['reason' => 'badRequest', 'message' => 'secret-token'], ['reason' => 'secret-token']]]];
    $safe = Transport::safeError($raw);
    $this->assertSame(['error' => ['status' => 'INVALID_ARGUMENT', 'reasons' => ['badRequest']]], $safe);
    $this->assertSame($safe, Transport::safeError($safe));
    $this->assertStringNotContainsString('secret-token', json_encode($safe));
});

test('plain mcp error text includes only safe upstream diagnostics', function (): void {
    $error = new ServiceError('MUSIC_UPSTREAM_REJECTED', 'Music rejected the request.', [
        'upstream_http_status' => 400, 'upstream_status' => 'INVALID_ARGUMENT',
        'upstream_reasons' => ['badRequest', 'private-token'],
        'music_client_name' => 'WEB_REMIX', 'music_client_version' => '1.20261011.16.00',
        'music_visitor_context_present' => true, 'visitorData' => 'private-token',
        'music_request_profile' => 'oauth_minimal_v1',
        'access_token' => 'private-token', 'response_body' => 'private-token',
    ]);
    $text = Diagnostics::text($error);
    $this->assertStringContainsString('upstream_http_status=400', $text);
    $this->assertStringContainsString('upstream_status=INVALID_ARGUMENT', $text);
    $this->assertStringContainsString('upstream_reasons=badRequest', $text);
    $this->assertStringContainsString('music_client_version=1.20261011.16.00', $text);
    $this->assertStringContainsString('music_request_profile=oauth_minimal_v1', $text);
    $this->assertStringNotContainsString('private-token', $text);
    $this->assertArrayNotHasKey('response_body', Diagnostics::safe($error->details));
});

test('safe diagnostic log excludes upstream bodies credentials and raw visitor identifiers', function (): void {
    $dir = $this->tempDir();
    $file = $dir . '/music-errors.log';
    $previous = ini_get('error_log');
    try {
        ini_set('error_log', $file);
        Diagnostics::log(new ServiceError('MUSIC_UPSTREAM_REJECTED', 'private-message', [
            'upstream_http_status' => 400, 'upstream_status' => 'INVALID_ARGUMENT',
            'upstream_reasons' => ['badRequest'], 'access_token' => 'private-secret', 'response_body' => 'private-secret',
        ]), 'browse');
        $logged = file_get_contents($file);
        $this->assertStringContainsString('music_upstream_error', $logged);
        $this->assertStringContainsString('"upstream_http_status":400', $logged);
        $this->assertStringContainsString('badRequest', $logged);
        $this->assertStringNotContainsString('private-secret', $logged);
        $this->assertStringNotContainsString('private-message', $logged);
    } finally {
        ini_set('error_log', $previous);
        $this->removeDir($dir);
    }
});

test('malformed diagnostic values are discarded and unrelated errors keep their original text', function (): void {
    $this->assertSame([], Diagnostics::safe(['upstream_http_status' => 'secret', 'upstream_status' => 'secret',
        'music_client_version' => "1.20261011.16.00\nsecret", 'music_visitor_context_present' => 'secret']));
    $this->assertSame('INVALID_ARGUMENT: Invalid field.', Diagnostics::text(new ServiceError('INVALID_ARGUMENT', 'Invalid field.')));
});
