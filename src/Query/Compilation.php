<?php

declare(strict_types=1);

namespace Orion\Query;

use Orion\IndexName;

/**
 * What the engine would receive for a query, from {@see Searcher::compile()}: the bound and scoped query, the
 * physical index it targets, and either the one native request or, when core fuses, the two legs and the fusion
 * that would combine them. Nothing here was sent.
 */
final readonly class Compilation
{
    /**
     * @param array<array-key, mixed>|null $native the request, when the engine answers the query in one
     * @param array{lexical: array<array-key, mixed>, vector: array<array-key, mixed>}|null $legs the two requests, when core fuses
     */
    public function __construct(
        public string $engine,
        public IndexName $index,
        public Query $query,
        public ?array $native,
        public ?array $legs,
        public ?Fusion $fusion,
    ) {
    }

    /**
     * @return array{engine: string, index: string, query: array<string, mixed>, native: array<array-key, mixed>|null, legs: array<string, array<array-key, mixed>>|null, fusion: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'engine' => $this->engine,
            'index' => $this->index->value,
            'query' => $this->query->toArray(),
            'native' => $this->native,
            'legs' => $this->legs,
            'fusion' => $this->fusion?->toArray(),
        ];
    }
}
