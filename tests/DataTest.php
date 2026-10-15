<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests;

use UnexpectedValueException;
use YouTubeMcp\Data;

covers(Data::class);

test('nested data access distinguishes missing optional fields from malformed containers', function (): void {
    $data = ['items' => [['snippet' => ['title' => 'Fixture', 'position' => 0]]]];

    expect(Data::get($data, 'items', 0, 'snippet', 'title'))->toBe('Fixture');
    expect(Data::get($data, 'items', 0, 'snippet', 'position'))->toBe(0);
    expect(Data::get($data, 'items', 0, 'snippet', 'missing'))->toBeNull();
    expect(Data::get($data, 'items', 1, 'snippet', 'title'))->toBeNull();
    expect(Data::get(null, 'snippet', 'title'))->toBeNull();
    expect(fn() => Data::get(['snippet' => 'private-fixture-value'], 'snippet', 'title'))
        ->toThrow(UnexpectedValueException::class, 'Expected an array.');
});

test('boundary validation preserves lists and scalar conversions without coercing structured values', function (): void {
    expect(Data::object(['value' => null]))->toBe(['value' => null]);
    expect(Data::objects([['id' => 'one'], ['id' => 'two']]))->toBe([['id' => 'one'], ['id' => 'two']]);
    expect(Data::strings(['one', 'two']))->toBe(['one', 'two']);
    expect(Data::strings([]))->toBe([]);
    expect(Data::text(42))->toBe('42');
    expect(Data::text(false))->toBe('');
    expect(Data::text(null))->toBe('');
    expect(Data::string('42'))->toBe('42');

    foreach ([
        fn() => Data::string(42),
        fn() => Data::text(['private-fixture-value']),
        fn() => Data::scalar(new \stdClass()),
        fn() => Data::array('private-fixture-value'),
        fn() => Data::object(['one']),
        fn() => Data::objects(['one']),
        fn() => Data::objects([1 => ['id' => 'one']]),
        fn() => Data::strings(['one', 2]),
        fn() => Data::strings(['key' => 'one']),
    ] as $invalid) {
        expect($invalid)->toThrow(UnexpectedValueException::class);
    }
});
