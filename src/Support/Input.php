<?php

declare(strict_types=1);

namespace Orion\Support;

use InvalidArgumentException;

use function array_is_list;
use function array_key_exists;
use function get_debug_type;
use function in_array;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Reads declarative input (an agent's JSON, a `search:` manifest) into typed values. Every failure names the path
 * of the offending value, and unknown keys are refused rather than ignored, so a typo fails instead of silently
 * dropping a filter. An explicit null for an optional key means the key is absent, as JSON clients send it.
 */
final class Input
{
    /**
     * @param list<string> $required
     * @param list<string> $optional
     * @return array<string, mixed>
     */
    public static function object(mixed $value, string $path, array $required, array $optional = []): array
    {
        /** @var array<string, mixed> $object keys outside the string lists below are refused */
        $object = self::map($value, $path);
        foreach ($required as $key) {
            if (!array_key_exists($key, $object)) {
                throw new InvalidArgumentException(sprintf('%s.%s is required.', $path, $key));
            }
        }
        foreach ($object as $key => $_) {
            if (!in_array($key, $required, true) && !in_array($key, $optional, true)) {
                throw new InvalidArgumentException(sprintf('%s.%s is not a known key.', $path, self::key($key)));
            }
        }

        return $object;
    }

    /**
     * An object with arbitrary keys, such as fields by name. PHP turns numeric-looking keys into ints, so callers
     * validate each key as a name before using it.
     *
     * @return array<array-key, mixed>
     */
    public static function map(mixed $value, string $path): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw self::invalid($path, 'an object', $value);
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    public static function list(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw self::invalid($path, 'a list', $value);
        }

        return $value;
    }

    public static function string(mixed $value, string $path): string
    {
        return is_string($value) ? $value : throw self::invalid($path, 'a string', $value);
    }

    public static function int(mixed $value, string $path): int
    {
        return is_int($value) ? $value : throw self::invalid($path, 'an integer', $value);
    }

    public static function number(mixed $value, string $path): int|float
    {
        return is_int($value) || (is_float($value) && is_finite($value)) ? $value : throw self::invalid($path, 'a finite number', $value);
    }

    public static function bool(mixed $value, string $path): bool
    {
        return is_bool($value) ? $value : throw self::invalid($path, 'a boolean', $value);
    }

    public static function scalar(mixed $value, string $path): string|int|float|bool
    {
        return is_string($value) || is_int($value) || (is_float($value) && is_finite($value)) || is_bool($value)
            ? $value
            : throw self::invalid($path, 'a string, finite number or boolean', $value);
    }

    /**
     * A key for a path: as is when it is a plain identifier, quoted and truncated otherwise.
     */
    private static function key(string|int $key): string
    {
        return preg_match('/^[A-Za-z0-9_]{1,64}\z/', (string) $key) === 1 ? (string) $key : Assert::show($key);
    }

    private static function invalid(string $path, string $expected, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('%s must be %s, %s given.', $path, $expected, get_debug_type($value)));
    }
}
