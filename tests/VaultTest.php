<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests;

use YouTubeMcp\Auth\Vault;

test('encryption and wrong key', function (): void {
    $v = new Vault(base64_encode(random_bytes(32)));
    $encrypted = $v->encrypt(['refresh_token' => 'secret']);
    $this->assertStringNotContainsString('secret', $encrypted);
    $this->assertSame(['refresh_token' => 'secret'], $v->decrypt($encrypted));
    $this->expectException(\RuntimeException::class);
    (new Vault(base64_encode(random_bytes(32))))->decrypt($encrypted);
});
