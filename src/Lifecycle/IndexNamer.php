<?php

declare(strict_types=1);

namespace Orion\Lifecycle;

use Orion\IndexName;
use Orion\Support\Assert;

use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Physical indices carry a version suffix; the application only ever talks to the alias (v1 §8.2).
 */
final class IndexNamer
{
    private const string FORMAT = '%s_v%d';
    private const string SHAPE = '/_v[1-9][0-9]*\z/';

    public static function physical(IndexName $alias, int $version): IndexName
    {
        return IndexName::of(sprintf(self::FORMAT, $alias->value, Assert::positive($version, 'An index version')));
    }

    /**
     * The version a physical index is of the alias, or null when it is not one of the alias's versions.
     */
    public static function versionOf(IndexName $alias, IndexName $physical): ?int
    {
        $prefix = $alias->value . '_v';
        if (!str_starts_with($physical->value, $prefix) || preg_match('/^[1-9][0-9]{0,8}\z/', substr($physical->value, strlen($prefix))) !== 1) {
            return null;
        }

        return (int) substr($physical->value, strlen($prefix));
    }

    /**
     * Whether a name is shaped like a physical index, so no alias may take it: `shop_v1` would collide with version 1 of `shop`.
     */
    public static function isPhysical(IndexName $name): bool
    {
        return preg_match(self::SHAPE, $name->value) === 1;
    }
}
