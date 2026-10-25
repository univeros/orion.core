<?php

declare(strict_types=1);

namespace Orion\Query;

/**
 * The result envelope, identical across engines and for the native escape hatch. Scores are engine-relative and
 * the order is not deterministic (v1 §17); nothing here claims otherwise.
 */
final readonly class ResultSet
{
    /**
     * @param list<Hit> $hits
     * @param array<string, array<string, int>> $facets bucket counts by field, then by value
     */
    public function __construct(
        public array $hits,
        public int $total,
        public bool $totalIsEstimate,
        public array $facets,
        public float $tookMs,
        public ?FusionTrace $fusion = null,
    ) {
    }
}
