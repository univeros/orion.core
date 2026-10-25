<?php

declare(strict_types=1);

namespace Orion\Query;

final readonly class Hit
{
    /**
     * @param array<string, mixed> $source
     * @param array<string, list<string>> $highlights fragments by field
     */
    public function __construct(
        public string $id,
        public float $score,
        public array $source,
        public array $highlights,
    ) {
    }
}
