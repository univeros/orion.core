<?php

declare(strict_types=1);

namespace Orion\Support;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The default PSR-14 dispatcher: returns each event unchanged, so core emits events unconditionally.
 */
final class NullEventDispatcher implements EventDispatcherInterface
{
    #[Override]
    public function dispatch(object $event): object
    {
        return $event;
    }
}
