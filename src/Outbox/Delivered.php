<?php

declare(strict_types=1);

namespace Orion\Outbox;

/**
 * What became of a batch of entries sent to an engine: applied, refused as stale by version (converged, not failed),
 * or failed with the engine's reason.
 */
final readonly class Delivered
{
    /**
     * @param list<string> $settled ids applied or stale
     * @param list<array{entry: Entry, error: string}> $failures
     */
    public function __construct(public int $applied, public int $stale, public array $settled, public array $failures)
    {
    }
}
