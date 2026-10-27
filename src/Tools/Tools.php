<?php

declare(strict_types=1);

namespace Orion\Tools;

use Orion\Wiring\Graph;

/**
 * The agent surface over one graph (v1 §15): six read-only tools and two mutating ones, `apply` and `reindex`,
 * which preview until confirmed. `compile_query` is the keystone: the native request for a neutral query, as the
 * engine would receive it, without running it.
 */
final class Tools
{
    /** Every tool's name, sorted. */
    public const array NAMES = ['apply', 'capabilities', 'compile_query', 'describe_index', 'list_indices', 'plan', 'reindex', 'search'];

    /**
     * @return list<Tool>
     */
    public static function for(Graph $graph): array
    {
        return [
            new ListIndices($graph),
            new DescribeIndex($graph),
            new Capabilities($graph),
            new CompileQuery($graph),
            new Search($graph),
            new Plan($graph),
            new Apply($graph),
            new Reindex($graph),
        ];
    }
}
