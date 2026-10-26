<?php

declare(strict_types=1);

namespace Orion\Lifecycle;

/**
 * What {@see Planner::apply()} did. The values are what a receipt (WP4) records as its operation.
 */
enum Operation: string
{
    /** Version 1 was built and the alias points at it. */
    case Created = 'created';
    /** The additive plan was applied to the live index in place. */
    case Applied = 'applied';
    /** The plan was empty. */
    case Unchanged = 'unchanged';
    /** Breaking changes, or an additive plan the engine refused: nothing was touched; the Reindexer (WP4) takes over. */
    case ReindexRequired = 'reindex_required';
}
