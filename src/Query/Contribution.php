<?php

declare(strict_types=1);

namespace Orion\Query;

/**
 * What one ranking added to one fused hit: the hit's 1-based rank in that ranking and the score it earned there.
 */
final readonly class Contribution
{
    public function __construct(
        public string $id,
        public string $source,
        public int $rank,
        public float $score,
    ) {
    }
}
