<?php

declare(strict_types=1);

namespace Orion\Outbox;

/**
 * What an outbox entry asks of the engine; the value is stored in `orion_outbox.operation`.
 */
enum EntryKind: string
{
    case Index = 'index';
    case Delete = 'delete';
}
