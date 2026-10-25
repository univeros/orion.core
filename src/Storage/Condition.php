<?php

declare(strict_types=1);

namespace Orion\Storage;

use DateTimeImmutable;
use InvalidArgumentException;

use function get_debug_type;
use function is_scalar;
use function sprintf;

/**
 * A non-equality criterion. Use as a criteria value: `['recorded_at' => Condition::lt($now)]`. The value is a scalar,
 * null or a DateTimeImmutable. Comparing against NULL never matches, as in SQL.
 */
final readonly class Condition
{
    public const string LT = '<';
    public const string LTE = '<=';
    public const string GT = '>';
    public const string GTE = '>=';
    public const string NE = '!=';
    public const string NOT_NULL = 'not null';

    private function __construct(public string $operator, public mixed $value)
    {
        if ($value !== null && !is_scalar($value) && !$value instanceof DateTimeImmutable) {
            throw new InvalidArgumentException(sprintf('A condition compares a scalar, null or DateTimeImmutable, %s given.', get_debug_type($value)));
        }
    }

    public static function lt(mixed $value): self
    {
        return new self(self::LT, $value);
    }

    public static function lte(mixed $value): self
    {
        return new self(self::LTE, $value);
    }

    public static function gt(mixed $value): self
    {
        return new self(self::GT, $value);
    }

    public static function gte(mixed $value): self
    {
        return new self(self::GTE, $value);
    }

    public static function ne(mixed $value): self
    {
        return new self(self::NE, $value);
    }

    public static function notNull(): self
    {
        return new self(self::NOT_NULL, null);
    }
}
