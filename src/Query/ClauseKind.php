<?php

declare(strict_types=1);

namespace Orion\Query;

enum ClauseKind: string
{
    /** Analysed full-text match. */
    case Match = 'match';
    /** Exact value, not analysed. */
    case Term = 'term';
    case Prefix = 'prefix';
    /** The terms adjacent and in order. */
    case Phrase = 'phrase';
}
