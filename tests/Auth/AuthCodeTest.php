<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Auth;

use DateTimeImmutable;
use YouTubeMcp\Auth\{AuthCode, Client, Scope};

covers(AuthCode::class);

test('authorization codes bind identity client redirect expiration and deduplicated scopes', function (): void {
    $code = new AuthCode();
    $client = new Client('client', 'Application', ['https://example.test/callback']);
    $expiry = new DateTimeImmutable('+5 minutes');
    $code->setIdentifier('code-id');
    $code->setClient($client);
    $code->setUserIdentifier('user-id');
    $code->setExpiryDateTime($expiry);
    $code->setRedirectUri('https://example.test/callback');
    $code->addScope(new Scope('playlists.read'));
    $code->addScope(new Scope('playlists.read'));

    expect($code->getIdentifier())->toBe('code-id');
    expect($code->getClient())->toBe($client);
    expect($code->getUserIdentifier())->toBe('user-id');
    expect($code->getRedirectUri())->toBe('https://example.test/callback');
    expect($code->getExpiryDateTime())->toBe($expiry);
    expect($code->getScopes())->toHaveCount(1);
});
