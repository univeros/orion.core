<?php

declare(strict_types=1);

namespace Orion\Outbox;

use Orion\IndexName;

/**
 * What a dead-letter retry did: entries now applied or stale (cleared), still failing (attempt counted), and
 * superseded (a newer entry replaced the document's, so the letter was dropped unsent).
 */
final readonly class Retried
{
    public function __construct(public IndexName $index, public int $applied, public int $stale, public int $failed, public int $superseded)
    {
    }

    /**
     * @return array{index: string, applied: int, stale: int, failed: int, superseded: int}
     */
    public function toArray(): array
    {
        return ['index' => $this->index->value, 'applied' => $this->applied, 'stale' => $this->stale, 'failed' => $this->failed, 'superseded' => $this->superseded];
    }
}
