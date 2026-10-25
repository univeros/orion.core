<?php

declare(strict_types=1);

namespace Orion\Write;

enum WriteStatus: string
{
    case Applied = 'applied';
    /** Refused by version because a newer write is already in the index: converged, not failed. */
    case Stale = 'stale';
    /** Goes to the dead letters for retry. */
    case Failed = 'failed';
}
