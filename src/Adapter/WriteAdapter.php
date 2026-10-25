<?php

declare(strict_types=1);

namespace Orion\Adapter;

use Orion\IndexName;
use Orion\Write\BulkResult;
use Orion\Write\Document;

interface WriteAdapter
{
    /** Version-stamped where the engine reports `Capability::OptimisticConcurrency`. */
    public function index(IndexName $index, Document ...$docs): BulkResult;

    public function delete(IndexName $index, string ...$ids): BulkResult;

    /** Refresh or commit; an honest no-op where the engine has neither. */
    public function flush(IndexName $index, bool $await = false): void;
}
