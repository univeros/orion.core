<?php

declare(strict_types=1);

namespace Orion\Lifecycle;

use Orion\IndexName;
use Orion\Support\Assert;

use function sprintf;

/**
 * Physical indices carry a version suffix; the application only ever talks to the alias (v1 §8.2).
 */
final class IndexNamer
{
    private const string FORMAT = '%s_v%d';

    public static function physical(IndexName $alias, int $version): IndexName
    {
        return IndexName::of(sprintf(self::FORMAT, $alias->value, Assert::positive($version, 'An index version')));
    }
}
