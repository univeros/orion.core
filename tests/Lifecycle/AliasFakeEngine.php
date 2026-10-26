<?php

declare(strict_types=1);

namespace Orion\Tests\Lifecycle;

use Orion\Adapter\Capability;
use Orion\Adapter\InMemory\InMemoryEngine;
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
use RuntimeException;

/**
 * The in-memory engine claiming native aliases, recording what it is asked to alias, and failing on demand.
 */
final class AliasFakeEngine implements SearchAdapter, SchemaAdapter, WriteAdapter
{
    /** @var array<string, string> alias => physical */
    public array $aliases = [];
    public bool $failNextAlias = false;

    public function __construct(private readonly InMemoryEngine $inner = new InMemoryEngine())
    {
    }

    public function alias(IndexName $alias, IndexName $physical): void
    {
        if ($this->failNextAlias) {
            $this->failNextAlias = false;
            throw new RuntimeException('The engine refused the alias.');
        }
        $this->aliases[$alias->value] = $physical->value;
    }

    public function supports(Capability $capability): bool
    {
        return $capability === Capability::NativeAliases || $this->inner->supports($capability);
    }

    public function search(IndexName $index, Query $query): ResultSet
    {
        return $this->inner->search($this->resolve($index), $query);
    }

    public function raw(IndexName $index, callable $native, Filter ...$scope): ResultSet
    {
        return $this->inner->raw($this->resolve($index), $native, ...$scope);
    }

    public function ensure(IndexSchema $schema): void
    {
        $this->inner->ensure($schema);
    }

    public function inspect(IndexName $index): ?LiveSchema
    {
        return $this->inner->inspect($this->resolve($index));
    }

    public function plan(IndexSchema $desired): MigrationPlan
    {
        return $this->inner->plan($desired);
    }

    public function apply(MigrationPlan $plan): PendingTask
    {
        return $this->inner->apply($plan);
    }

    public function drop(IndexName $index): void
    {
        $this->inner->drop($index);
    }

    public function index(IndexName $index, Document ...$docs): BulkResult
    {
        return $this->inner->index($this->resolve($index), ...$docs);
    }

    public function delete(IndexName $index, Tombstone ...$tombstones): BulkResult
    {
        return $this->inner->delete($this->resolve($index), ...$tombstones);
    }

    public function flush(IndexName $index, bool $await = false): void
    {
        $this->inner->flush($this->resolve($index), $await);
    }

    private function resolve(IndexName $index): IndexName
    {
        return isset($this->aliases[$index->value]) ? IndexName::of($this->aliases[$index->value]) : $index;
    }
}
