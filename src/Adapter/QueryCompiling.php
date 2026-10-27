<?php

declare(strict_types=1);

namespace Orion\Adapter;

use Orion\IndexName;
use Orion\Query\Query;

/**
 * An engine that can show the request its {@see SearchAdapter::search()} would send for a query, without sending
 * it (v1 §15, `compile_query`). The shape is the adapter's own (a search body, request parameters); it must be the
 * one `search()` builds, deterministic, and JSON for an agent to read. The searcher alone calls it, on the bound and
 * scoped query, so what it shows is exactly what the engine would receive. Compiling sends no search; an adapter
 * may still read the live schema to build the form (Solr reads field types for range bounds, as its search does).
 */
interface QueryCompiling
{
    /**
     * @return array<array-key, mixed>
     * @throws UnsupportedClause when the query needs a capability the engine lacks
     */
    public function compile(IndexName $index, Query $query): array;
}
