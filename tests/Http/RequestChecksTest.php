<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use YouTubeMcp\Http\RequestChecks;
use YouTubeMcp\ServiceError;

covers(RequestChecks::class);

test('csrf accepts array and object parsed bodies and rejects missing or incorrect tokens', function (): void {
    $previousSession = $_SESSION ?? [];
    $_SESSION['csrf'] = 'fixture-csrf';
    try {
        $request = new ServerRequest('POST', 'https://music.example.test/disconnect');
        foreach ([['csrf' => 'fixture-csrf'], (object) ['csrf' => 'fixture-csrf']] as $body) {
            RequestChecks::csrf($request->withParsedBody($body));
        }
        foreach ([null, [], (object) [], ['csrf' => 'wrong'], (object) ['csrf' => 'wrong']] as $body) {
            expect(fn() => RequestChecks::csrf($request->withParsedBody($body)))
                ->toThrow(ServiceError::class, 'Reload the page and retry.');
        }
        unset($_SESSION['csrf']);
        expect(fn() => RequestChecks::csrf($request->withParsedBody(['csrf' => 'fixture-csrf'])))
            ->toThrow(ServiceError::class, 'Reload the page and retry.');
    } finally {
        $_SESSION = $previousSession;
    }
});
