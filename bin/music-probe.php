<?php

declare(strict_types=1);

use YouTubeMusicMcp\{Config, ServiceError};
use YouTubeMusicMcp\Auth\{Google, MusicOAuth, MusicTokenDiagnostics, Vault};
use YouTubeMusicMcp\Http\Client;
use YouTubeMusicMcp\Music\Api as MusicApi;
use YouTubeMusicMcp\Quota\Budget;
use YouTubeMusicMcp\Storage\{Database, Lock};
use YouTubeMusicMcp\YouTube\Api;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Run as the Plesk subscription user, not root.\n");
    exit(2);
}
require dirname(__DIR__) . '/vendor/autoload.php';

$channel = $argv[1] ?? '';
if (!preg_match('/^UC[A-Za-z0-9_-]{22}$/D', $channel)) {
    fwrite(STDERR, "Usage: php bin/music-probe.php CHANNEL_ID\nRun as the subscription user from the deployed project. No secrets belong in arguments.\n");
    exit(2);
}
try {
    $config = Config::load(dirname(__DIR__));
    $config->validate();
    if (!is_file($config->storage() . '/service.sqlite')) {
        throw new ServiceError('STORAGE_UNAVAILABLE', 'Existing storage is required.');
    }
    $db = new Database($config->storage() . '/service.sqlite');
    $rows = $db->all('SELECT user_id FROM google_connections WHERE channel_id=?', [$channel]);
    if (count($rows) !== 1) {
        throw new ServiceError('AMBIGUOUS_CONNECTION', 'Exactly one connected service user must match this channel.');
    }
    $user = $rows[0]['user_id'];
    $locks = new Lock($config->storage() . '/locks');
    $vault = new Vault($config->get('APP_KEY'));
    $http = new Client();
    $google = new Google($config, $db, $vault, $http, $locks);
    $youtube = new Api($http, new Budget($db, $config), $db, $google);
    $music = new MusicOAuth($config, $db, $google, $vault, $http, $youtube, $locks);
    if (!$music->configured()) {
        throw new ServiceError('MUSIC_OAUTH_SETUP_REQUIRED', 'Separate Music OAuth must be configured.');
    }
    $report = $locks->run('account:' . $user, function () use ($music, $google, $user, $config, $http) {
        $connection = $music->connection($user, $google->connection($user));
        $token = $music->accessToken($user, $connection);
        return ['token_check' => (new MusicTokenDiagnostics($config, $http))->inspect($token)]
            + (new MusicApi($config))->compareHome($token);
    });
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    // Never echo exception messages, traces, configuration or database contents.
    fwrite(STDERR, json_encode(['code' => $error instanceof ServiceError ? $error->reason : 'INTERNAL_ERROR',
        'error_class' => get_class($error)], JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}
