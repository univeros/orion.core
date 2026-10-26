<?php

declare(strict_types=1);

namespace Orion\Tests\Wiring;

use LogicException;
use Orion\Adapter\Capability;
use Orion\Adapter\PendingTask;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Query\Filter;
use Orion\Query\Query;
use Orion\Query\ResultSet;
use Orion\Schema\IndexSchema;
use Orion\Schema\LiveSchema;
use Orion\Schema\MigrationPlan;
use Orion\Write\BulkResult;
use Orion\Write\Document;
use Orion\Write\Tombstone;

/**
 * An engine that only exists to be wired; every verb refuses.
 */
final class FakeEngine implements SearchAdapter, SchemaAdapter, WriteAdapter
{
    public function search(IndexName $index, Query $query): ResultSet
    {
        throw new LogicException('Not wired for searching.');
    }

    public function raw(IndexName $index, callable $native, Filter ...$scope): ResultSet
    {
        throw new LogicException('Not wired for searching.');
    }

    public function supports(Capability $capability): bool
    {
        return false;
    }

    public function ensure(IndexSchema $schema): void
    {
        throw new LogicException('Not wired for schemas.');
    }

    public function inspect(IndexName $index): ?LiveSchema
    {
        return null;
    }

    public function plan(IndexSchema $desired): MigrationPlan
    {
        return MigrationPlan::between($desired, null);
    }

    public function apply(MigrationPlan $plan): PendingTask
    {
        return PendingTask::completed();
    }

    public function drop(IndexName $index): void
    {
    }

    public function alias(IndexName $alias, IndexName $physical): void
    {
        throw new LogicException('Not wired for aliases.');
    }

    public function resolve(IndexName $alias): ?IndexName
    {
        return null;
    }

    public function index(IndexName $index, Document ...$docs): BulkResult
    {
        return BulkResult::completed();
    }

    public function delete(IndexName $index, Tombstone ...$tombstones): BulkResult
    {
        return BulkResult::completed();
    }

    public function flush(IndexName $index, bool $await = false): void
    {
    }
}
