<?php

declare(strict_types=1);

namespace YouTubeMusicMcp;

use Dotenv\Dotenv;
use RuntimeException;

final class Config
{
    public function __construct(private array $values = [], public readonly string $root = __DIR__ . '/..')
    {
    }

    public static function load(string $root): self
    {
        Dotenv::createImmutable($root)->safeLoad();
        return new self(array_merge($_SERVER, $_ENV), $root);
    }

    public function bool(string $name, bool $default = false): bool
    {
        return filter_var($this->get($name, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    public function get(string $name, string $default = ''): string
    {
        $environment = getenv($name);
        return (string)($this->values[$name] ?? ($environment === false ? $default : $environment));
    }

    public function storage(): string
    {
        $path = $this->get('STORAGE_PATH', 'var');
        return str_starts_with($path, '/') || preg_match(
            '/^[A-Za-z]:[\\\\\/]/',
            $path
        ) ? $path : $this->root . '/' . $path;
    }

    public function list(string $name): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->get($name)))));
    }

    public function validate(): void
    {
        $location = $this->get('MUSIC_LOCATION');
        $version = $this->get('MUSIC_CLIENT_VERSION');
        if ($location !== '' && !preg_match('/^[A-Z]{2}$/D', $location)) {
            throw new RuntimeException('MUSIC_LOCATION must be a two-letter country code.');
        }
        if ($version !== '' && !preg_match('/^[0-9.]{8,64}$/D', $version)) {
            throw new RuntimeException('Invalid MUSIC_CLIENT_VERSION.');
        }
        if (!preg_match('~^https://[^/]+$~', $this->url())) {
            throw new RuntimeException('APP_URL must be an HTTPS origin without a path.');
        }
        $key = base64_decode($this->get('APP_KEY'), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_KEY must be a base64 encoded 32-byte key.');
        }
    }

    public function url(): string
    {
        return rtrim($this->get('APP_URL'), '/');
    }

    public function int(string $name, int $default): int
    {
        return (int)$this->get($name, (string)$default);
    }
}
