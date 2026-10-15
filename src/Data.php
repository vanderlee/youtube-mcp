<?php

declare(strict_types=1);

namespace YouTubeMcp;

use UnexpectedValueException;

/**
 * Validates values at JSON, session and storage boundaries without coercing their shape.
 *
 * Keeps untrusted or decoded data explicit before services rely on its types.
 */
final class Data
{
    public static function text(mixed $value): string
    {
        return (string) self::scalar($value);
    }

    public static function get(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            if ($value === null) {
                return null;
            }
            $array = self::array($value);
            if (!array_key_exists($key, $array)) {
                return null;
            }
            $value = $array[$key];
        }

        return $value;
    }

    /** @return scalar|null */
    public static function scalar(mixed $value): string|int|float|bool|null
    {
        if (!is_scalar($value) && $value !== null) {
            throw new UnexpectedValueException('Expected a scalar value.');
        }

        return $value;
    }

    public static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new UnexpectedValueException('Expected a string.');
        }

        return $value;
    }

    public static function integer(mixed $value): int
    {
        if (!is_int($value)) {
            throw new UnexpectedValueException('Expected an integer.');
        }

        return $value;
    }

    /** @return array<array-key, mixed> */
    public static function array(mixed $value): array
    {
        if (!is_array($value)) {
            throw new UnexpectedValueException('Expected an array.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public static function object(mixed $value): array
    {
        $value = self::array($value);
        foreach ($value as $key => $_) {
            if (!is_string($key)) {
                throw new UnexpectedValueException('Expected an object with string keys.');
            }
        }

        return $value;
    }

    /** @return list<array<string, mixed>> */
    public static function objects(mixed $value): array
    {
        $value = self::array($value);
        if (!array_is_list($value)) {
            throw new UnexpectedValueException('Expected a list of objects.');
        }

        return array_map(self::object(...), $value);
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        $value = self::array($value);
        if (!array_is_list($value)) {
            throw new UnexpectedValueException('Expected a list of strings.');
        }

        return array_map(self::string(...), $value);
    }
}
