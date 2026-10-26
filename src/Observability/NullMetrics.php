<?php

declare(strict_types=1);

namespace Orion\Observability;

use Override;

final readonly class NullMetrics implements Metrics
{
    #[Override]
    public function counter(string $name, float $value = 1.0, array $attributes = []): void
    {
    }

    #[Override]
    public function gauge(string $name, float $value, array $attributes = []): void
    {
    }
}
