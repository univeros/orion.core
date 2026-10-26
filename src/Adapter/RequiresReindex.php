<?php

declare(strict_types=1);

namespace Orion\Adapter;

use LogicException;
use Orion\IndexName;

use function sprintf;

/**
 * A plan that cannot be applied to the live index in place: it has breaking changes, or the engine cannot make an
 * additive change live (Elasticsearch cannot add analysis to an existing index). The planner routes it to a reindex;
 * nothing was changed.
 */
final class RequiresReindex extends LogicException
{
    public static function for(IndexName $index, string $reason): self
    {
        return new self(sprintf('The plan for %s needs a reindex, never an in-place apply: %s', $index->value, $reason));
    }
}
