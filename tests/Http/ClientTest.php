<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Http;

covers(\YouTubeMcp\Http\Client::class);

test('https connections are reused without retaining request bodies or other identities', function (): void {
    $directory = $this->tempDir();
    $server = null;
    $serverPipes = [];
    try {
        $config = $directory . '/openssl.cnf';
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[extensions]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'config' => $config]);
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['config' => $config, 'req_extensions' => 'extensions']);
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['config' => $config, 'x509_extensions' => 'extensions']);
        openssl_x509_export($certificate, $public);
        openssl_pkey_export($key, $private, null, ['config' => $config]);
        file_put_contents($directory . '/ca.pem', $public);
        file_put_contents($directory . '/server.pem', $public . $private);
        $server = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/fixtures/https-server.php', $directory . '/server.pem'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $serverPipes,
        );
        $address = trim(fgets($serverPipes[1]));
        expect($address)->toMatch('/^127\.0\.0\.1:\d+$/');
        $client = proc_open(
            [PHP_BINARY, '-d', 'curl.cainfo=' . $directory . '/ca.pem', dirname(__DIR__) . '/fixtures/https-client.php', $address],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $clientPipes,
        );
        $result = stream_get_contents($clientPipes[1]);
        $error = stream_get_contents($clientPipes[2]);
        fclose($clientPipes[1]);
        fclose($clientPipes[2]);
        expect(proc_close($client))->toBe(0, $error);
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        expect($data[0]['data']['connection'])->toBe(1);
        expect($data[1]['data'])->toBe(['connection' => 1, 'method' => 'GET', 'authorization' => 'Bearer dummy-a', 'body' => '']);
        expect($data[2]['data'])->toBe(['connection' => 2, 'method' => 'GET', 'authorization' => 'Bearer dummy-b', 'body' => '']);
    } finally {
        if (is_resource($server)) {
            proc_terminate($server);
            foreach ($serverPipes as $pipe) {
                fclose($pipe);
            }
            proc_close($server);
        }
        $this->removeDir($directory);
    }
});
