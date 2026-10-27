<?php

declare(strict_types=1);

namespace Orion\Query;

use stdClass;

use function array_map;

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

    /**
     * The envelope as JSON data: an empty map is an object, so `facets` and a hit's `source` and `highlights` keep
     * their shape when nothing is in them. The fusion trace is not included; a caller that wants it appends
     * {@see FusionTrace::toArray()}.
     *
     * @return array{hits: list<array<string, mixed>>, total: int, total_is_estimate: bool, facets: array<string, array<string, int>|stdClass>|stdClass, took_ms: float}
     */
    public function toArray(): array
    {
        return [
            'hits' => array_map(static fn(Hit $hit): array => $hit->toArray(), $this->hits),
            'total' => $this->total,
            'total_is_estimate' => $this->totalIsEstimate,
            'facets' => self::map(array_map(self::map(...), $this->facets)),
            'took_ms' => $this->tookMs,
        ];
    }

    /**
     * @template T
     * @param array<string, T> $map
     * @return array<string, T>|stdClass
     */
    public static function map(array $map): array|stdClass
    {
        return $map === [] ? new stdClass() : $map;
    }
}
