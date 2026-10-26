<?php

declare(strict_types=1);

namespace Orion\Tests\Support;

use Orion\Observability\Metrics;

final class RecordingMetrics implements Metrics
{
    /** @var array<string, float> name|attributes => total */
    public array $counters = [];
    /** @var array<string, float> name|attributes => last value */
    public array $gauges = [];

    public function counter(string $name, float $value = 1.0, array $attributes = []): void
    {
        $key = self::key($name, $attributes);
        $this->counters[$key] = ($this->counters[$key] ?? 0.0) + $value;
    }

    public function gauge(string $name, float $value, array $attributes = []): void
    {
        $this->gauges[self::key($name, $attributes)] = $value;
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function key(string $name, array $attributes): string
    {
        $pairs = [];
        foreach ($attributes as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return $name . '|' . implode(',', $pairs);
    }
}
