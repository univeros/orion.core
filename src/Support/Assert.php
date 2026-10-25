<?php

declare(strict_types=1);

namespace Orion\Support;

use InvalidArgumentException;

use function is_finite;
use function is_float;
use function is_string;
use function json_encode;
use function preg_match;
use function sprintf;
use function strlen;
use function substr;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Invariants the value objects share, and the one way messages echo a value.
 */
final class Assert
{
    /** A field name: a letter, then letters, digits and `_`; no engine metadata (`_id`, `_source`), no wildcards. */
    private const string NAME = '[A-Za-z][A-Za-z0-9_]*';
    private const int MAX_NAME_LENGTH = 255;
    /** Strings longer than this are not search input. */
    public const int MAX_VALUE_LENGTH = 4096;
    private const int MAX_SHOWN = 64;

    public static function notEmpty(string $value, string $what): string
    {
        return $value !== '' ? $value : throw new InvalidArgumentException(sprintf('%s must not be empty.', $what));
    }

    public static function positive(int $value, string $what): int
    {
        return $value > 0 ? $value : throw new InvalidArgumentException(sprintf('%s must be positive, %d given.', $what, $value));
    }

    public static function atMost(int $value, int $max, string $what): int
    {
        return $value <= $max ? $value : throw new InvalidArgumentException(sprintf('%s must be at most %d, %d given.', $what, $max, $value));
    }

    public static function finite(float $value, string $what): float
    {
        return is_finite($value) ? $value : throw new InvalidArgumentException(sprintf('%s must be a finite number.', $what));
    }

    /**
     * A schema field name.
     */
    public static function fieldName(string $name, string $what): string
    {
        return self::matches('/^' . self::NAME . '\z/', $name, $what, 'letters, digits and "_", starting with a letter');
    }

    /**
     * A field addressed by a query: a field name, or a dotted path into object fields.
     */
    public static function fieldPath(string $path, string $what): string
    {
        return self::matches('/^' . self::NAME . '(\.' . self::NAME . ')*\z/', $path, $what, 'a field name or a dotted path of field names');
    }

    /**
     * A literal search value: finite when a number, bounded when a string.
     */
    public static function value(string|int|float|bool $value, string $what): string|int|float|bool
    {
        if (is_float($value)) {
            self::finite($value, $what);
        }
        if (is_string($value)) {
            self::atMost(strlen($value), self::MAX_VALUE_LENGTH, $what . '\'s length');
        }

        return $value;
    }

    /**
     * A value for an error message: JSON-quoted and truncated, so input cannot forge log lines or flood them.
     */
    public static function show(string|int $value): string
    {
        $value = (string) $value;
        $shown = strlen($value) > self::MAX_SHOWN ? substr($value, 0, self::MAX_SHOWN) . '…' : $value;

        return (string) json_encode($shown, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function matches(string $pattern, string $value, string $what, string $rule): string
    {
        if (strlen($value) > self::MAX_NAME_LENGTH || preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s %s is invalid: use %s, at most %d bytes.', $what, self::show($value), $rule, self::MAX_NAME_LENGTH));
        }

        return $value;
    }
}
