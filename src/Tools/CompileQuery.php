<?php

declare(strict_types=1);

namespace Orion\Tools;

use Orion\Query\Query;
use Orion\Wiring\Graph;
use Override;

/**
 * The keystone (v1 §15): a neutral query in its declarative form compiles to the native request the engine would
 * receive, bound to the schema and scoped to the tenant, without running. When core fuses a hybrid query, the two
 * legs and the fusion are shown instead of one request.
 */
final readonly class CompileQuery implements Tool
{
    public function __construct(private Graph $graph)
    {
    }

    public const string NAME = 'compile_query';
    public const string DESCRIPTION = 'Compile a neutral query to the native request the engine would receive (bound to the registered schema, scoped to the tenant), without executing it. A hybrid query fused in core shows its lexical and vector legs and the fusion.';

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[Override]
    public function description(): string
    {
        return self::DESCRIPTION;
    }

    #[Override]
    public function inputSchema(): array
    {
        return QuerySchema::input();
    }

    #[Override]
    public function mutates(): bool
    {
        return false;
    }

    #[Override]
    public function call(array $input): array
    {
        return $this->graph->searcher()->compile(Query::fromArray($input))->toArray();
    }
}
