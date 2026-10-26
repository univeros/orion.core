<?php

declare(strict_types=1);

namespace Orion\Query;

use InvalidArgumentException;
use Orion\IndexName;
use Orion\Lifecycle\AliasManager;
use Orion\Registry\EngineRegistry;
use Orion\Registry\IndexRegistration;
use Orion\Support\Assert;
use Orion\Tenancy\ScopeInjector;

use function sprintf;

/**
 * The one way Orion searches (v1 §9): the index the alias serves now, on its engine, with the tenant's scope
 * injected beneath the query, the escape hatch included, and every hit checked to be the tenant's before it is
 * handed back with the application's id.
 */
final readonly class Searcher
{
    public function __construct(private AliasManager $aliases, private EngineRegistry $engines, private ScopeInjector $scopes)
    {
    }

    public function search(Query $query): ResultSet
    {
        $current = $this->current($query->index);
        $scope = $this->scopes->scope($current->schema);

        return $scope->results($this->engines->get($current->engine)->search($this->aliases->targetOf($current), $scope->query($query)));
    }

    /**
     * The native escape hatch: `$native` is the engine's own query (each adapter documents its shape), restricted
     * to the tenant by the adapter.
     */
    public function raw(IndexName $index, callable $native): ResultSet
    {
        $current = $this->current($index);
        $scope = $this->scopes->scope($current->schema);

        return $scope->results($this->engines->get($current->engine)->raw($this->aliases->targetOf($current), $native, ...$scope->filters()));
    }

    private function current(IndexName $alias): IndexRegistration
    {
        return $this->aliases->current($alias)
            ?? throw new InvalidArgumentException(sprintf('%s is not built: apply its schema first.', Assert::show($alias->value)));
    }
}
