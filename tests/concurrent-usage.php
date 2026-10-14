<?php

declare(strict_types=1);
// Test worker, not an application entry point.
require dirname(__DIR__) . '/vendor/autoload.php';
$db = new \YouTubeMcp\Storage\Database($argv[1]);
$usage = new \YouTubeMcp\Quota\Usage($db);
for ($request = 0; $request < 10; $request++) {
    $id = $usage->dispatch($argv[2], 'playlistItems.insert', 'fixture-' . $argv[2]);
    $usage->outcome($id, 'success');
}
echo 'recorded';
