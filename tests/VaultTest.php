<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

use PHPUnit\Framework\TestCase;
use YouTubeMusicMcp\Auth\Vault;

final class VaultTest extends TestCase
{
    public function testEncryptionAndWrongKey(): void
    {
        $v = new Vault(base64_encode(random_bytes(32)));
        $encrypted = $v->encrypt(['refresh_token' => 'secret']);
        self::assertStringNotContainsString('secret', $encrypted);
        self::assertSame(['refresh_token' => 'secret'], $v->decrypt($encrypted));
        $this->expectException(\RuntimeException::class);
        (new Vault(base64_encode(random_bytes(32))))->decrypt($encrypted);
    }
}
