<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests;

use RuntimeException;
use YouTubeMcp\Config;

covers(Config::class);

test('configuration resolves typed values and private storage paths', function (): void {
    $config = $this->config(['ENABLED' => 'yes', 'COUNT' => '12', 'MEMBERS' => ' a, ,b ', 'STORAGE_PATH' => 'private']);

    expect($config->bool('ENABLED'))->toBeTrue();
    expect($config->bool('MISSING', true))->toBeTrue();
    expect($config->int('COUNT', 1))->toBe(12);
    expect($config->int('MISSING', 7))->toBe(7);
    expect($config->list('MEMBERS'))->toBe(['a', 'b']);
    expect($config->get('MISSING', 'fallback'))->toBe('fallback');
    expect($config->storage())->toBe($config->root . '/private');
    expect($this->config(['STORAGE_PATH' => '/srv/private'])->storage())->toBe('/srv/private');
    expect($this->config(['STORAGE_PATH' => 'C:/private'])->storage())->toBe('C:/private');
});

test('invalid production configuration is rejected', function (array $values, string $message): void {
    expect(fn() => $this->config($values)->validate())->toThrow(RuntimeException::class, $message);
})->with([
    'http origin' => [['APP_URL' => 'http://example.test'], 'APP_URL'],
    'origin with path' => [['APP_URL' => 'https://example.test/path'], 'APP_URL'],
    'short key' => [['APP_KEY' => base64_encode('short')], 'APP_KEY'],
    'invalid base64' => [['APP_KEY' => '!invalid'], 'APP_KEY'],
    'negative cache ttl' => [['API_READ_CACHE_TTL' => '-1'], 'API_READ_CACHE_TTL'],
    'excess cache ttl' => [['API_READ_CACHE_TTL' => '301'], 'API_READ_CACHE_TTL'],
    'fractional cache ttl' => [['API_READ_CACHE_TTL' => '1.5'], 'API_READ_CACHE_TTL'],
    'zero idle timeout' => [['SESSION_IDLE_SECONDS' => '0'], 'SESSION_IDLE_SECONDS'],
    'zero absolute timeout' => [['SESSION_ABSOLUTE_SECONDS' => '0'], 'SESSION_ABSOLUTE_SECONDS'],
]);

test('cache ttl boundaries are valid', function (string $ttl): void {
    $this->config(['API_READ_CACHE_TTL' => $ttl])->validate();
    expect($this->config(['API_READ_CACHE_TTL' => $ttl])->int('API_READ_CACHE_TTL', -1))->toBe((int) $ttl);
})->with(['0', '300']);

test('configuration loads an isolated environment file and retains its root', function (): void {
    $directory = $this->tempDir();
    $previousEnvironment = $_ENV;
    $previousServer = $_SERVER;
    try {
        file_put_contents($directory . '/.env', "UNIT_CONFIG_VALUE=from-file\n");
        unset($_ENV['UNIT_CONFIG_VALUE'], $_SERVER['UNIT_CONFIG_VALUE']);
        $config = Config::load($directory);
        expect($config->get('UNIT_CONFIG_VALUE'))->toBe('from-file');
        expect($config->root)->toBe($directory);
    } finally {
        $_ENV = $previousEnvironment;
        $_SERVER = $previousServer;
        $this->removeDir($directory);
    }
});
