<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Auth;

use YouTubeMcp\Auth\Vault;

covers(\YouTubeMcp\Auth\Vault::class);

test('encryption and wrong key', function (): void {
    $v = new Vault(base64_encode(random_bytes(32)));
    $encrypted = $v->encrypt(['refresh_token' => 'secret']);
    $this->assertStringNotContainsString('secret', $encrypted);
    $this->assertSame(['refresh_token' => 'secret'], $v->decrypt($encrypted));
    $this->expectException(\RuntimeException::class);
    (new Vault(base64_encode(random_bytes(32))))->decrypt($encrypted);
});
