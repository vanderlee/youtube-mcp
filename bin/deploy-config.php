<?php

declare(strict_types=1);
// Private CLI helper for post-deploy.sh. Never place this directory in the web root.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$command = $argv[1] ?? '';
try {
    if ($command === 'runtime') {
        if (PHP_VERSION_ID < 80200) {
            throw new RuntimeException('PHP 8.2+ is required.');
        }
        foreach (
            [
                'curl',
                'fileinfo',
                'pdo',
                'pdo_sqlite',
                'sodium',
                'openssl',
                'mbstring',
                'session',
                'json'
            ] as $ext
        ) {
            if (!extension_loaded($ext)) {
                throw new RuntimeException('Required PHP extension missing: ' . $ext);
            }
        }
        echo 'PHP runtime checks passed (' . PHP_VERSION . ').' . PHP_EOL;
        exit;
    }
    require $root . '/vendor/autoload.php';
    if ($command === 'dependencies') {
        require $root . '/vendor/composer/platform_check.php';
        echo "Dependency runtime checks passed.\n";
        exit;
    }
    $load = static function () use ($root): array {
        return is_file($root . '/.env') ? \Dotenv\Dotenv::parse(file_get_contents($root . '/.env')) : [];
    };
    $values = $load();
    if ($command === 'read') {
        $name = $argv[2] ?? '';
        echo $values[$name] ?? ($name === 'STORAGE_PATH' ? $root . '/var' : '');
        exit;
    }
    if ($command === 'has') {
        echo empty($values[$argv[2] ?? '']) ? 'no' : 'yes';
        exit;
    }
    $outsidePublic = static function (string $path) use ($root): void {
        $public = realpath($root . '/public');
        $probe = $path;
        while (!file_exists($probe) && dirname($probe) !== $probe) {
            $probe = dirname($probe);
        }
        $resolved = realpath($probe);
        if (!$resolved || $resolved === $public || str_starts_with($resolved, $public . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Private storage must be outside public/.');
        }
        if (str_contains($path, "\n") || str_contains($path, "\r") || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid storage path.');
        }
    };
    if ($command === 'prepare') {
        $existing = is_file($root . '/.env');
        if (is_link($root . '/.env')) {
            throw new RuntimeException('Refusing to replace a symlinked .env.');
        }
        $template = $existing ? file_get_contents($root . '/.env') : file_get_contents($root . '/.env.example');
        $values = \Dotenv\Dotenv::parse($template);
        foreach (
            [
                'APP_URL',
                'STORAGE_PATH',
                'ALLOWED_EMAILS',
                'GOOGLE_CLIENT_ID',
                'GOOGLE_CLIENT_SECRET',
                'OAUTH_REDIRECT_URIS',
                'MUSIC_CLIENT_VERSION',
                'MUSIC_LOCATION',
                'MUSIC_OAUTH_CLIENT_ID',
                'MUSIC_OAUTH_CLIENT_SECRET'
            ] as $name
        ) {
            $input = getenv('DEPLOY_' . $name);
            if ($input !== false && $input !== '') {
                $values[$name] = $input;
            }
        }
        $values['STORAGE_PATH'] ??= $root . '/var';
        if (!$existing && (empty($values['APP_KEY']))) {
            $storage = $values['STORAGE_PATH'];
            if (!str_starts_with($storage, '/')) {
                $storage = $root . '/' . $storage;
            }
            if (file_exists($storage . '/service.sqlite') || file_exists($storage . '/oauth-private.pem')) {
                throw new RuntimeException(
                    'Existing state found without APP_KEY. Restore its original configuration instead of generating a new key.'
                );
            }
            $values['APP_KEY'] = base64_encode(random_bytes(32));
        } elseif (empty($values['APP_KEY'])) {
            throw new RuntimeException(
                'Existing .env has no APP_KEY. Restore the original key, or generate one manually only if no state exists.'
            );
        }
        $config = new \YouTubeMusicMcp\Config($values, $root);
        $config->validate();
        $outsidePublic($config->storage());
        foreach (['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'] as $name) {
            if (empty($values[$name])) {
                throw new RuntimeException(
                    'Configure ' . $name . ' using private .env or DEPLOY_' . $name . ' before running setup.'
                );
            }
        }
        if (!$config->bool('ALLOW_REGISTRATION') && !$config->list('ALLOWED_EMAILS')) {
            throw new RuntimeException('Invite at least one email or explicitly allow registration.');
        }
        foreach ($config->list('ALLOWED_EMAILS') as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid invited email address.');
            }
        }
        foreach ($config->list('OAUTH_REDIRECT_URIS') as $uri) {
            if (!filter_var($uri, FILTER_VALIDATE_URL) || !str_starts_with($uri, 'https://') || parse_url(
                    $uri,
                    PHP_URL_FRAGMENT
                ) !== null) {
                throw new RuntimeException('OAuth callbacks must be exact HTTPS URLs without fragments.');
            }
        }
        $quote = static function (string $value): string {
            if (preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new RuntimeException('Configuration values must be single-line strings.');
            }
            return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value) . '"';
        };
        $text = $template;
        foreach ($values as $name => $value) {
            $line = $name . '=' . $quote((string)$value);
            $pattern = '/^' . preg_quote($name, '/') . '\s*=.*$/m';
            if (preg_match($pattern, $text)) {
                $text = preg_replace_callback($pattern, fn() => $line, $text);
            } else {
                $text = rtrim($text) . "\n" . $line . "\n";
            }
        }
        // Parse before writing to ensure special characters survive serialization.
        if (\Dotenv\Dotenv::parse($text) !== $values) {
            throw new RuntimeException('Configuration serialization failed; .env unchanged.');
        }
        if ($existing && $text !== $template) {
            $backup = $root . '/.env.backup.' . gmdate('YmdHis') . '.' . bin2hex(random_bytes(3));
            file_put_contents($backup, $template);
            chmod($backup, 0600);
            echo "Private configuration backup created; keep it outside the web root.\n";
        }
        $temp = tempnam($root, '.env.tmp.');
        try {
            file_put_contents($temp, $text);
            chmod($temp, 0600);
            if (!rename($temp, $root . '/.env')) {
                throw new RuntimeException('Cannot install configuration.');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
        chmod($root . '/.env', 0600);
        if (!is_dir($config->storage()) && !mkdir($config->storage(), 0700, true)) {
            throw new RuntimeException('Cannot create private storage.');
        }
        chmod($config->storage(), 0700);
        echo "Private configuration prepared; encryption key preserved/generated without printing it.\n";
        exit;
    }
    $config = new \YouTubeMusicMcp\Config($values, $root);
    $config->validate();
    $outsidePublic($config->storage());
    if ($command === 'storage') {
        echo $config->storage();
        exit;
    }
    if ($command === 'verify') {
        $db = new \YouTubeMusicMcp\Storage\Database($config->storage() . '/service.sqlite');
        $db->one('SELECT name FROM migrations LIMIT 1');
        $private = $config->storage() . '/oauth-private.pem';
        $public = $config->storage() . '/oauth-public.pem';
        if (!is_readable($private) || !is_readable($public)) {
            throw new RuntimeException('OAuth signing keys missing.');
        }
        $key = openssl_pkey_get_private(file_get_contents($private));
        if (!$key || openssl_pkey_get_details($key)['key'] !== file_get_contents($public)) {
            throw new RuntimeException('OAuth signing keys do not match.');
        }
        echo "Storage, migrations, and signing-key checks passed.\n";
        exit;
    }
    if ($command === 'client') {
        $file = $config->storage() . '/chatgpt-client.json';
        if (file_exists($file)) {
            echo "Static client credentials already exist in private storage; no new client created.\n";
            exit;
        }
        $uris = $config->list('OAUTH_REDIRECT_URIS');
        if (count($uris) !== 1) {
            throw new RuntimeException(
                'Static creation requires exactly one callback; otherwise use client:create manually.'
            );
        }
        $db = new \YouTubeMusicMcp\Storage\Database($config->storage() . '/service.sqlite');
        $credentials = (new \YouTubeMusicMcp\Auth\OAuth($db, $config))->register(
            ['redirect_uris' => $uris, 'client_name' => 'ChatGPT', 'token_endpoint_auth_method' => 'client_secret_post']
        );
        file_put_contents($file, json_encode($credentials, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($file, 0600);
        echo "Static credentials saved to private storage as chatgpt-client.json. Enter them privately in ChatGPT setup.\n";
        exit;
    }
    if ($command === 'cron') {
        $line = $argv[2] ?? '';
        if (str_contains($line, "\n") || $line === '') {
            throw new RuntimeException('Invalid cron line.');
        }
        $run = static function (array $args, ?string $stdin = null): array {
            $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot invoke crontab.');
            }
            if ($stdin !== null) {
                fwrite($pipes[0], $stdin);
            }
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($process), $out, $err];
        };
        [$status, $out, $err] = $run(['crontab', '-l']);
        if ($status !== 0 && !str_contains(strtolower($err), 'no crontab')) {
            throw new RuntimeException('Cannot read current crontab; configure the task in Plesk instead.');
        }
        $marker = 'youtube-mcp-' . substr(hash('sha256', $root), 0, 16);
        $begin = '# BEGIN ' . $marker;
        $end = '# END ' . $marker;
        $out = preg_replace('/^' . preg_quote($begin, '/') . '\R.*?^' . preg_quote($end, '/') . '\R?/ms', '', $out);
        $new = rtrim($out) . "\n" . $begin . "\n" . $line . "\n" . $end . "\n";
        [$status] = $run(['crontab', '-'], $new);
        if ($status !== 0) {
            throw new RuntimeException('Cannot write current user crontab.');
        }
        echo "Hourly cleanup installed for the current user; other cron entries preserved.\n";
        exit;
    }
    if ($command === 'http') {
        $get = static function (string $url, string $method = 'GET'): array {
            $ch = curl_init($url);
            curl_setopt_array(
                $ch,
                [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => $method,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS
                ]
            );
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($body === false) {
                throw new RuntimeException('HTTPS probe failed. Check DNS, certificate, and routing.');
            }
            return [$status, $body];
        };
        foreach (['/health/live', '/health/ready'] as $path) {
            [$status, $body] = $get($config->url() . $path);
            $json = json_decode($body, true);
            if ($status !== 200 || !in_array($json['status'] ?? null, ['ok', 'ready'], true)) {
                throw new RuntimeException('Health check failed: ' . $path);
            }
            echo 'HTTPS check passed: ' . $path . PHP_EOL;
        }
        [$status, $body] = $get($config->url() . '/.well-known/oauth-authorization-server');
        $json = json_decode($body, true);
        if ($status !== 200 || ($json['issuer'] ?? null) !== $config->url()) {
            throw new RuntimeException('OAuth metadata does not match the configured origin.');
        }
        [$status] = $get($config->url() . '/mcp', 'POST');
        if ($status !== 401) {
            throw new RuntimeException('Unauthenticated MCP probe must return 401.');
        }
        foreach (['/.env', '/composer.json', '/var/oauth-private.pem'] as $path) {
            [$status, $body] = $get($config->url() . $path);
            if ($status === 200 && (str_contains($body, 'APP_KEY=') || str_contains(
                        $body,
                        'PRIVATE KEY-----'
                    ) || (json_decode($body, true)['name'] ?? null) === 'youtube-music-mcp/service')) {
                throw new RuntimeException(
                    'Private project file appears publicly accessible. Fix the document root immediately.'
                );
            }
        }
        echo "HTTPS metadata/authentication probes passed. Verify remaining private paths manually.\n";
        exit;
    }
    throw new RuntimeException('Unknown deployment helper command.');
} catch (Throwable $e) {
    $message = $e instanceof \Dotenv\Exception\InvalidFileException ? 'Cannot parse private configuration; fix .env syntax without exposing its contents.' : $e->getMessage();
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
