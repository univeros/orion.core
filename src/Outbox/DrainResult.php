<?php

declare(strict_types=1);

namespace Orion\Outbox;

use Orion\IndexName;

/**
 * Where an index's drain stands: its cursor before and after the run, the outbox head, the lag between them, and what
 * the run wrote. Lag is how far the index trails committed writes, surfaced rather than hidden (v1 §8.3).
 */
final readonly class DrainResult
{
    public int $lag;

    public function __construct(
        public IndexName $index,
        public int $from,
        public int $to,
        public int $head,
        public int $applied,
        public int $stale,
        public int $failed,
    ) {
        $this->lag = max(0, $head - $to);
    }

    /**
     * @return array{index: string, from: int, to: int, head: int, lag: int, applied: int, stale: int, failed: int}
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index->value,
            'from' => $this->from,
            'to' => $this->to,
            'head' => $this->head,
            'lag' => $this->lag,
            'applied' => $this->applied,
            'stale' => $this->stale,
            'failed' => $this->failed,
        ];
    }
}
