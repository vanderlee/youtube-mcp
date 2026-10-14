<?php

declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use YouTubeMcp\Config;
use YouTubeMcp\Storage\Database;

$root = dirname(__DIR__);
$command = $argv[1] ?? 'help';
try {
    if ($command === 'bootstrap:check') {
        foreach ([\YouTubeMcp\Config::class, \YouTubeMcp\App::class, \YouTubeMcp\Mcp\Tools::class, \YouTubeMcp\Mcp\Presentation::class] as $class) {
            if (!class_exists($class)) {
                throw new RuntimeException('Cannot autoload ' . $class . '. Check source files and regenerate Composer autoloading from the current composer.json.');
            }
            echo 'Autoload OK: ' . $class . PHP_EOL;
        }
        // Inspect the deployed class to detect files from different releases at runtime.
        if (!(new ReflectionClass(Config::class))->hasMethod('load')) {
            throw new RuntimeException('Config::load is missing. Application files are from different releases.');
        }
        echo "Application class checks passed; configuration, storage and PHP-FPM are not verified.\n";
        exit(0);
    }
    if ($command === 'mcp:check') {
        if (!class_exists(\YouTubeMcp\Mcp\Presentation::class)) {
            throw new RuntimeException('MCP presentation class missing. Deploy the complete release and verify Composer autoloading.');
        }
        $tools = \YouTubeMcp\Mcp\Tools::definitions();
        $names = [];
        $validator = new \Opis\JsonSchema\Validator();
        foreach ($tools as $tool) {
            if (isset($names[$tool->name])) {
                throw new RuntimeException('Duplicate tool name: ' . $tool->name);
            }
            $names[$tool->name] = true;
            $schema = json_decode(json_encode($tool->inputSchema, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            // Compiles each schema. Missing required arguments in this dummy value are expected.
            $validator->validate(new stdClass(), $schema);
            if (array_diff($tool->inputSchema['required'] ?? [], array_keys((array) $tool->inputSchema['properties']))) {
                throw new RuntimeException('Tool has required arguments absent from its properties: ' . $tool->name);
            }
        }
        echo 'MCP catalog check passed: ' . count($tools) . " tools.\n";
        echo 'Catalog fingerprint: ' . hash('sha256', json_encode($tools, JSON_THROW_ON_ERROR)) . "\n";
        echo "Offline check only; HTTPS, OAuth, PHP-FPM and ChatGPT refresh are not verified.\n";
        exit(0);
    }
    if ($command === 'key:generate') {
        echo base64_encode(random_bytes(32)) . PHP_EOL;
        exit(0);
    }
    $config = Config::load($root);
    if ($command === 'init') {
        $config->validate();
        $dir = $config->storage();
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        if (!is_file($dir . '/oauth-private.pem')) {
            $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if (!$key || !openssl_pkey_export($key, $private)) {
                throw new RuntimeException('Cannot generate OAuth key pair.');
            }
            file_put_contents($dir . '/oauth-private.pem', $private);
            chmod($dir . '/oauth-private.pem', 0600);
            file_put_contents($dir . '/oauth-public.pem', openssl_pkey_get_details($key)['key']);
            chmod($dir . '/oauth-public.pem', 0644);
        }
        $db = new Database($dir . '/service.sqlite');
        $db->migrate($root);
        echo "Initialized storage and migrations.\n";
        exit(0);
    }
    if ($command === 'migrate') {
        $db = new Database($config->storage() . '/service.sqlite');
        $db->migrate($root);
        echo "Migrations complete.\n";
        exit(0);
    }
    if ($command === 'cleanup') {
        $db = new Database($config->storage() . '/service.sqlite');
        (new \YouTubeMcp\Quota\Usage($db))->cleanup();
        $deferred = (new \YouTubeMcp\YouTube\MediaStore($db, $config))->cleanup(
            new \YouTubeMcp\Storage\Lock($config->storage() . '/locks'),
        );
        if ($deferred > 0) {
            echo 'Media cleanup deferred ' . $deferred . " expired asset(s) in busy accounts until the next run.\n";
        }
        $now = time();
        $cutoff = $now - 86400 * $config->int('OPERATION_RETENTION_DAYS', 7);
        $db->execute('DELETE FROM api_cache WHERE expires_at<=?', [$now]);
        $db->execute('DELETE FROM oauth_tokens WHERE expires_at<=?', [$now - 86400]);
        $db->execute('DELETE FROM rate_windows WHERE window<?', [intdiv($now, 60) - 60]);
        $db->execute("DELETE FROM operations WHERE updated_at<? AND status IN ('completed','partial')", [$cutoff]);
        $db->execute('DELETE FROM audit_events WHERE created_at<?', [$cutoff]);
        $db->execute('DELETE FROM quota_requests WHERE created_at<?', [$now - 30 * 86400]);
        $db->execute(
            'DELETE FROM quota_windows WHERE window<? AND reserved=0',
            [
                (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->modify('-30 days')->format(
                    'Y-m-d',
                ),
            ],
        );
        echo "Cleanup complete; interrupted/uncertain operations retained.\n";
        exit(0);
    }
    if ($command === 'client:create') {
        $config->validate();
        $uri = $argv[2] ?? '';
        if (!in_array($uri, $config->list('OAUTH_REDIRECT_URIS'), true)) {
            throw new RuntimeException('Add the exact callback URI to OAUTH_REDIRECT_URIS first.');
        }
        $db = new Database($config->storage() . '/service.sqlite');
        $oauth = new \YouTubeMcp\Auth\OAuth($db, $config);
        echo json_encode(
            $oauth->register(
                [
                    'redirect_uris' => [$uri],
                    'client_name' => $argv[3] ?? 'ChatGPT',
                    'token_endpoint_auth_method' => 'client_secret_post',
                ],
            ),
            JSON_PRETTY_PRINT,
        ) . PHP_EOL;
        exit(0);
    }
    echo "Commands: key:generate, init, migrate, cleanup, bootstrap:check, mcp:check, client:create CALLBACK_URI [NAME]\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
