<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Mcp;

use YouTubeMcp\Mcp\Arguments;
use YouTubeMcp\ServiceError;

covers(Arguments::class);

test('tool argument validation accepts empty schemas and valid bounded arrays', function (): void {
    expect(fn() => Arguments::validate([], [], []))->not->toThrow(ServiceError::class);
    $properties = [
        'ids' => [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 2,
            'uniqueItems' => true,
            'items' => ['type' => 'string', 'pattern' => '^[a-z]{11}$'],
        ],
    ];
    expect(fn() => Arguments::validate(['ids' => ['aaaaaaaaaaa']], $properties, ['ids']))
        ->not->toThrow(ServiceError::class);
});

test('schema validation rejects invalid inputs', function (array $input): void {
    $properties = ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]];
    expect(fn() => Arguments::validate($input, $properties, ['limit']))
        ->toThrow(ServiceError::class, 'Arguments do not match this tool schema.');
})->with([
    'missing' => [[]],
    'unknown' => [['limit' => 1, 'extra' => true]],
    'wrong type' => [['limit' => '1']],
    'below minimum' => [['limit' => 0]],
    'above maximum' => [['limit' => 51]],
]);
