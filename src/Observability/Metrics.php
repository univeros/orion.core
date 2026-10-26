<?php

declare(strict_types=1);

namespace Orion\Observability;

/**
 * Where Orion counts its work (docs/spec/revision.md §3): bind your metrics client; the core default discards.
 * Names are dotted (`orion.outbox.applied`); attributes are low-cardinality strings such as the index.
 */
interface Metrics
{
    /**
     * @param array<string, string> $attributes
     */
    public function counter(string $name, float $value = 1.0, array $attributes = []): void;

    /**
     * @param array<string, string> $attributes
     */
    public function gauge(string $name, float $value, array $attributes = []): void;
}
