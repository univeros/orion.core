<?php

declare(strict_types=1);

namespace Orion\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Reads raw driver values from a storage row: numbers may come back as strings, dates as UTC strings (adapters store
 * them in UTC), JSON as text.
 */
final class Row
{
    public static function int(mixed $value, string $path): int
    {
        if (is_string($value) && is_numeric($value) && (string) (int) $value === $value) {
            $value = (int) $value;
        }

        return is_int($value) ? $value : throw new InvalidArgumentException(sprintf('%s must be an integer.', $path));
    }

    public static function date(mixed $value, string $path): DateTimeImmutable
    {
        return $value instanceof DateTimeImmutable ? $value : new DateTimeImmutable(Input::string($value, $path), new DateTimeZone('UTC'));
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function json(mixed $value, string $path): array
    {
        $decoded = is_array($value) ? $value : json_decode(Input::string($value, $path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : throw new InvalidArgumentException(sprintf('%s must hold a JSON object or list.', $path));
    }
}
