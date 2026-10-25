<?php

declare(strict_types=1);

namespace Orion\Query;

final readonly class FusedRanking
{
    /**
     * @param list<Hit> $hits best first, scored by the fusion
     */
    public function __construct(public array $hits, public FusionTrace $trace)
    {
    }
}
