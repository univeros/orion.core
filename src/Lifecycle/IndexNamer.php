<?php

declare(strict_types=1);

namespace Orion\Lifecycle;

use Orion\IndexName;
use Orion\Support\Assert;

use function preg_match;
use function sprintf;

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
     * Whether a name is shaped like a physical index, so no alias may take it: `shop_v1` would collide with version 1 of `shop`.
     */
    public static function isPhysical(IndexName $name): bool
    {
        return preg_match(self::SHAPE, $name->value) === 1;
    }
}
