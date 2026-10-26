<?php

declare(strict_types=1);

namespace Orion\Support;

use function array_is_list;
use function is_array;
use function json_encode;
use function ksort;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const SORT_STRING;

/**
 * One byte representation per value, so equal declarations hash and compare equal whatever order they were written
 * in: objects are sorted by key at every depth, lists keep their order.
 */
final class Canonical
{
    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    public static function sort(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sort($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function json(array $value): string
    {
        return json_encode(self::sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
