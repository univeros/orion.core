<?php

declare(strict_types=1);

namespace Orion\Adapter;

use Orion\IndexName;
use Orion\Write\BulkResult;
use Orion\Write\Document;
use Orion\Write\Tombstone;

interface WriteAdapter
{
    /** Version-stamped where the engine reports `Capability::OptimisticConcurrency`. */
    public function index(IndexName $index, Document ...$docs): BulkResult;

    /** Version-stamped like writes: a tombstone older than the indexed document is stale and changes nothing. */
    public function delete(IndexName $index, Tombstone ...$tombstones): BulkResult;

    /** Refresh or commit; an honest no-op where the engine has neither. */
    public function flush(IndexName $index, bool $await = false): void;
}
