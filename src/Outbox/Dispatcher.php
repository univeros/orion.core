<?php

declare(strict_types=1);

namespace Orion\Outbox;

use Orion\IndexName;

/**
 * Hands an index's drain to whatever runs it (docs/spec/revision.md §3): now, by default, or later on the host's
 * queue. Call it after the transaction that recorded the entries commits; draining before the commit would write
 * documents the transaction may still roll back. Without a dispatcher, `orion outbox:drain` runs the drain.
 */
interface Dispatcher
{
    public function dispatch(IndexName $index): void;
}
