<?php

declare(strict_types=1);
// Test worker, not an application entry point.
require dirname(__DIR__) . '/vendor/autoload.php';
$db = new \YouTubeMusicMcp\Storage\Database($argv[1]);
$config = new \YouTubeMusicMcp\Config(
    ['QUOTA_GENERAL' => '200', 'QUOTA_HEADROOM' => '0', 'QUOTA_RECOVERY_RESERVE' => '0', 'USER_QUOTA_GENERAL' => '200']
);
$budget = new \YouTubeMusicMcp\Quota\Budget($db, $config);
try {
    $budget->reserve($argv[2], 150);
    echo 'admitted';
} catch (\YouTubeMusicMcp\ServiceError $e) {
    echo $e->reason;
}
