<?php

declare(strict_types=1);

namespace Orion\Adapter;

use Orion\IndexName;
use Orion\Query\Query;
use Orion\Query\ResultSet;

interface SearchAdapter
{
    /**
     * @throws UnsupportedClause when the query needs a capability the engine lacks
     */
    public function search(IndexName $index, Query $query): ResultSet;

    /**
     * The native escape hatch: `$native` receives the engine's own query builder; the envelope is the same.
     */
    public function raw(IndexName $index, callable $native): ResultSet;

    public function supports(Capability $capability): bool;
}
