<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Auth;

use YouTubeMcp\Auth\Client;

covers(Client::class);

test('oauth clients preserve exact redirects and explicit confidentiality', function (): void {
    $redirects = ['https://example.test/callback', 'https://example.test/other'];
    $public = new Client('public-client', 'Public application', $redirects);
    $confidential = new Client('private-client', 'Private application', $redirects, true);

    expect($public->getIdentifier())->toBe('public-client');
    expect($public->getName())->toBe('Public application');
    expect($public->getRedirectUri())->toBe($redirects);
    expect($public->isConfidential())->toBeFalse();
    expect($confidential->isConfidential())->toBeTrue();
});
