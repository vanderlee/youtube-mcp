<?php

declare(strict_types=1);

// Isolated loopback fixture: two keep-alive requests, then a different authorization identity.
$context = stream_context_create(['ssl' => ['local_cert' => $argv[1], 'verify_peer' => false]]);
$server = stream_socket_server('tls://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "Cannot start loopback TLS fixture.\n");
    exit(1);
}
echo stream_socket_get_name($server, false) . PHP_EOL;
fflush(STDOUT);

foreach ([2, 1] as $connection => $count) {
    $socket = stream_socket_accept($server, 15);
    if ($socket === false) {
        exit(2);
    }
    stream_set_timeout($socket, 10);
    for ($n = 0; $n < $count; $n++) {
        $line = fgets($socket);
        if ($line === false) {
            exit(3);
        }
        $headers = [];
        while (($header = fgets($socket)) !== false && trim($header) !== '') {
            [$name, $value] = explode(':', $header, 2);
            $headers[strtolower($name)] = trim($value);
        }
        $remaining = (int) ($headers['content-length'] ?? 0);
        $body = '';
        while ($remaining > 0) {
            $chunk = fread($socket, $remaining);
            if ($chunk === false || $chunk === '') {
                exit(4);
            }
            $body .= $chunk;
            $remaining -= strlen($chunk);
        }
        $json = json_encode(['connection' => $connection + 1, 'method' => explode(' ', $line)[0],
            'authorization' => $headers['authorization'] ?? null, 'body' => $body], JSON_THROW_ON_ERROR);
        $keepAlive = $n + 1 < $count ? 'keep-alive' : 'close';
        fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\nConnection: $keepAlive\r\n\r\n$json");
    }
    fclose($socket);
}
fclose($server);
