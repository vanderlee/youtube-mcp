<?php

declare(strict_types=1);

namespace YouTubeMcp;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Loads and validates environment settings for application services.
 *
 * Provides typed access to deployment paths, security settings and permitted origins.
 */
final class Config
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values = [], public readonly string $root = __DIR__ . '/..') {}

    public static function load(string $root): self
    {
        Dotenv::createImmutable($root)->safeLoad();

        return new self(Data::object(array_merge($_SERVER, $_ENV)), $root);
    }

    public function bool(string $name, bool $default = false): bool
    {
        return filter_var($this->get($name, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    public function get(string $name, string $default = ''): string
    {
        $environment = getenv($name);

        return Data::text($this->values[$name] ?? ($environment === false ? $default : $environment));
    }

    public function storage(): string
    {
        $path = $this->get('STORAGE_PATH', 'var');

        return str_starts_with($path, '/') || preg_match(
            '/^[A-Za-z]:[\\\\\/]/',
            $path,
        ) ? $path : $this->root . '/' . $path;
    }

    /**
     * @return list<string>
     */
    public function list(string $name): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->get($name)))));
    }

    public function validate(): void
    {
        $this->clientOrigins();
        $this->oauthRedirectOrigins();

        $ttl = $this->get('API_READ_CACHE_TTL', '30');
        if (!ctype_digit($ttl) || (int) $ttl > 300) {
            throw new RuntimeException('API_READ_CACHE_TTL must be an integer between 0 and 300 seconds.');
        }
        foreach (['SESSION_IDLE_SECONDS' => 1800, 'SESSION_ABSOLUTE_SECONDS' => 43200] as $name => $default) {
            if ($this->int($name, $default) < 1) {
                throw new RuntimeException($name . ' must be a positive number of seconds.');
            }
        }

        if (!preg_match('~^https://[^/]+$~', $this->url())) {
            throw new RuntimeException('APP_URL must be an HTTPS origin without a path.');
        }

        $this->appKey();
    }

    public function appKey(): string
    {
        $key = base64_decode($this->get('APP_KEY'), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_KEY must be a base64 encoded 32-byte key.');
        }

        return $key;
    }

    public function url(): string
    {
        return rtrim($this->get('APP_URL'), '/');
    }

    /** @return list<string> */
    public function clientOrigins(): array
    {
        $origins = array_filter(array_map('trim', explode(',', $this->get(
            'MCP_CLIENT_ORIGINS',
            'https://chatgpt.com,https://claude.ai,https://claude.com',
        ))));
        foreach ($origins as $origin) {
            if ($this->oauthOrigin($origin) !== $origin) {
                throw new RuntimeException('MCP_CLIENT_ORIGINS must contain exact origins without paths.');
            }
        }

        return array_values(array_unique([$this->url(), ...$origins]));
    }

    /** @return list<string> */
    public function oauthRedirectOrigins(): array
    {
        return array_values(array_unique(array_map($this->oauthOrigin(...), $this->list('OAUTH_REDIRECT_URIS'))));
    }

    private function oauthOrigin(string $uri): string
    {
        $parts = parse_url($uri);
        if (
            $parts === false || !filter_var($uri, FILTER_VALIDATE_URL)
            || preg_match('/[\s;\x27"<>]/', $uri)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        ) {
            throw new RuntimeException('OAuth client URLs must be valid HTTPS URLs or HTTP loopback URLs.');
        }
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true))) {
            throw new RuntimeException('OAuth client URLs must be valid HTTPS URLs or HTTP loopback URLs.');
        }

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public function int(string $name, int $default): int
    {
        return (int) $this->get($name, (string) $default);
    }
}
