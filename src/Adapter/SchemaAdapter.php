<?php

declare(strict_types=1);

namespace Orion\Adapter;

use Orion\IndexName;
use Orion\Schema\IndexSchema;
use Orion\Schema\LiveSchema;
use Orion\Schema\MigrationPlan;

interface SchemaAdapter
{
    /** Idempotent create. */
    public function ensure(IndexSchema $schema): void;

    /** What is live, or null when the index does not exist. */
    public function inspect(IndexName $index): ?LiveSchema;

    /** The deterministic diff; adapters delegate to {@see MigrationPlan::between()}. */
    public function plan(IndexSchema $desired): MigrationPlan;

    /** Applies the additive changes in place; breaking ones are never applied to a live index. */
    public function apply(MigrationPlan $plan): PendingTask;

    /** Deletes a physical index; a no-op when it does not exist. */
    public function drop(IndexName $index): void;
}
