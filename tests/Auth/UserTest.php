<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Auth;

use YouTubeMcp\Auth\User;

covers(User::class);

test('oauth user retains the service identity rather than a channel identifier', function (): void {
    expect((new User('service-user-1'))->getIdentifier())->toBe('service-user-1');
});
