<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests;

use YouTubeMcp\ServiceError;

covers(ServiceError::class);

test('service error details cannot replace the public code or message', function (): void {
    $error = new ServiceError(
        'NOT_OWNER',
        'Access denied.',
        ['code' => 'spoofed', 'message' => 'spoofed', 'retry' => false],
        403
    );

    expect($error->payload())->toBe(['code' => 'NOT_OWNER', 'message' => 'Access denied.', 'retry' => false]);
    expect($error->reason)->toBe('NOT_OWNER');
    expect($error->httpStatus)->toBe(403);
    expect((new ServiceError('INVALID', 'Invalid input.'))->httpStatus)->toBe(400);
});
