<?php

declare(strict_types=1);

namespace Orion\Query;

use function array_map;

/**
 * How a fused ranking was produced, for explain and `compile_query`: the strategy and every contribution, ordered by
 * source name, then rank.
 */
final readonly class FusionTrace
{
    /**
     * @param list<Contribution> $contributions
     */
    public function __construct(public Fusion $fusion, public array $contributions)
    {
    }

    /**
     * @return array<string, mixed> the fusion's form plus `contributions`
     */
    public function toArray(): array
    {
        return [
            ...$this->fusion->toArray(),
            'contributions' => array_map(
                static fn(Contribution $c): array => ['id' => $c->id, 'source' => $c->source, 'rank' => $c->rank, 'score' => $c->score],
                $this->contributions,
            ),
        ];
    }
}
