<?php

declare(strict_types=1);

namespace Orion\Lifecycle;

/**
 * What a lifecycle operation did. The values are a receipt's `operation`; only the operations that change the index or
 * its registration (created, applied, reindexed, dropped) are receipted.
 */
enum Operation: string
{
    /** Version 1 was built and the alias points at it. */
    case Created = 'created';
    /** The declaration was applied in place: an additive plan to the live index, or a query-time change to the registration. */
    case Applied = 'applied';
    /** The plan was empty. */
    case Unchanged = 'unchanged';
    /** Breaking changes, or an additive plan the engine refused: nothing was touched; the Reindexer takes over. */
    case ReindexRequired = 'reindex_required';
    /** A new version was built by replay and the alias swapped onto it. */
    case Reindexed = 'reindexed';
    /** A version no longer behind the alias was dropped after its grace window. */
    case Dropped = 'dropped';
}
