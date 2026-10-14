<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Http;

use YouTubeMcp\Http\MediaClient;
use YouTubeMcp\ServiceError;

covers(MediaClient::class);

test('media transport refuses insecure schemes without exposing the request body', function (): void {
    // cURL rejects this protocol before opening a connection.
    try {
        (new MediaClient())->exchange('POST', 'http://127.0.0.1:1/private', [], 'dummy-secret-body');
        $this->fail('Insecure request was accepted.');
    } catch (ServiceError $error) {
        expect($error->reason)->toBe('UPSTREAM_UNCERTAIN');
        expect($error->httpStatus)->toBe(502);
        expect($error->getMessage())->not->toContain('dummy-secret-body');
    }
});
