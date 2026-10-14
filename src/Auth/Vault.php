<?php

declare(strict_types=1);

namespace YouTubeMcp\Auth;

use RuntimeException;

final class Vault
{
    private string $key;

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Invalid encryption key.');
        }

        $this->key = $key;
    }

    public function encrypt(array $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode(
            $nonce . sodium_crypto_secretbox(json_encode($value, JSON_THROW_ON_ERROR), $nonce, $this->key)
        );
    }

    public function decrypt(string $value): array
    {
        $raw = base64_decode($value, true);
        if (
            $raw === false
            || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES
        ) {
            throw new RuntimeException('Invalid encrypted credentials.');
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );
        if ($plain === false) {
            throw new RuntimeException('Cannot decrypt credentials.');
        }

        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }
}
