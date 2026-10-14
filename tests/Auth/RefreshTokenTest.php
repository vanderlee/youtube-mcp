<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Auth;

use DateTimeImmutable;
use YouTubeMcp\Auth\{AccessToken, RefreshToken};

covers(RefreshToken::class);

test('refresh tokens retain the originating access token and independent expiration', function (): void {
    $access = new AccessToken('https://example.test');
    $refresh = new RefreshToken();
    $expiry = new DateTimeImmutable('+1 day');
    $refresh->setIdentifier('refresh-id');
    $refresh->setAccessToken($access);
    $refresh->setExpiryDateTime($expiry);

    expect($refresh->getIdentifier())->toBe('refresh-id');
    expect($refresh->getAccessToken())->toBe($access);
    expect($refresh->getExpiryDateTime())->toBe($expiry);
});
