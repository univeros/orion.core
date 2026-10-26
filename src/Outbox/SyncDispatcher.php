<?php

declare(strict_types=1);

namespace Orion\Outbox;

use Orion\IndexName;
use Override;

/**
 * The core default: drains the index at once, in the caller's process.
 */
final readonly class SyncDispatcher implements Dispatcher
{
    public function __construct(private Drain $drain)
    {
    }

    #[Override]
    public function dispatch(IndexName $index): void
    {
        $this->drain->run($index);
    }
}
