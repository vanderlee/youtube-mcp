<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\YouTube;

use YouTubeMcp\Mcp\Arguments;
use YouTubeMcp\ServiceError;
use YouTubeMcp\YouTube\CreatorTools;

covers(CreatorTools::class);

test('creator writes require receipt keys and valid upload privacy', function (): void {
    $specs = CreatorTools::specs();
    foreach (CreatorTools::WRITES as $name) {
        expect($specs[$name][2])->toContain('operation_key');
        expect($specs[$name][1]['resume']['type'])->toBe('boolean');
    }
    [, $properties, $required] = $specs['upload_video'];
    expect($properties['privacy']['enum'])->toContain('private');
    $arguments = ['asset_id' => str_repeat('a', 48), 'title' => 'Fixture', 'operation_key' => 'fixture-operation'];
    expect(fn () => Arguments::validate($arguments, $properties, $required))->not->toThrow(ServiceError::class);
    $arguments['privacy'] = 'unknown';
    expect(fn () => Arguments::validate($arguments, $properties, $required))->toThrow(ServiceError::class);
});
