<?php

declare(strict_types=1);

namespace Orion\Tools;

use Orion\Query\Query;
use Orion\Wiring\Graph;
use Override;

final readonly class Search implements Tool
{
    public function __construct(private Graph $graph)
    {
    }

    public const string NAME = 'search';
    public const string DESCRIPTION = 'Run a neutral query through the scoped searcher and return the normalised result set: hits with source and highlights, total, facets, and the fusion trace of a hybrid query.';

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
        $query = Query::fromArray($input);
        $results = $this->graph->searcher()->search($query);

        return [
            ...$results->toArray(),
            'page' => $query->page->page,
            'size' => $query->page->size,
            ...($results->fusion === null ? [] : ['fusion' => $results->fusion->toArray()]),
        ];
    }
}
