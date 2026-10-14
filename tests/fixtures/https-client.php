<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$client = new \YouTubeMcp\Http\Client();
$url = 'https://' . $argv[1] . '/fixture';
$headers = ['Authorization: Bearer dummy-a', 'Content-Type: application/json'];
$first = $client->request('POST', $url, $headers, '{"dummy":true}');
$second = $client->request('GET', $url, $headers);
$third = $client->request('GET', $url, ['Authorization: Bearer dummy-b', 'Content-Type: application/json']);
echo json_encode([$first, $second, $third], JSON_THROW_ON_ERROR);
