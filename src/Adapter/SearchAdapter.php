<?php

declare(strict_types=1);

namespace Orion\Adapter;

use Orion\IndexName;
use Orion\Query\Filter;
use Orion\Query\Query;
use Orion\Query\ResultSet;

interface SearchAdapter
{
    /**
     * @throws UnsupportedClause when the query needs a capability the engine lacks
     */
    public function search(IndexName $index, Query $query): ResultSet;

    /**
     * The native escape hatch: `$native` builds the engine's own query (each adapter documents the callable's
     * shape); the envelope is the same. The `$scope` filters are compiled and ANDed with the native query, so a
     * restriction core applies (the tenant, from WP6) holds for the escape hatch too.
     */
    public function raw(IndexName $index, callable $native, Filter ...$scope): ResultSet;

    public function supports(Capability $capability): bool;
}
