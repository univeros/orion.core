<?php

declare(strict_types=1);

namespace Orion\Query;

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
}
