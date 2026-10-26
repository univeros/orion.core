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

    /**
     * The deterministic diff of fields against this engine's index; adapters delegate to {@see MigrationPlan::between()}.
     * What no engine reports (the org key, native settings) is diffed by `Lifecycle\Planner` against the registry,
     * which is the authoritative plan.
     */
    public function plan(IndexSchema $desired): MigrationPlan;

    /**
     * Applies the additive changes in place.
     *
     * @throws RequiresReindex for breaking changes, and for an additive change this engine cannot make live
     */
    public function apply(MigrationPlan $plan): PendingTask;

    /** Deletes a physical index; a no-op when it does not exist. */
    public function drop(IndexName $index): void;

    /**
     * Points the alias at exactly this physical index, atomically, whatever it pointed at before (v1 §8.2).
     *
     * @throws UnsupportedClause when the engine lacks {@see Capability::NativeAliases}: core then keeps the indirection
     */
    public function alias(IndexName $alias, IndexName $physical): void;
}
