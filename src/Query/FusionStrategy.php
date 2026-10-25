<?php

declare(strict_types=1);

namespace Orion\Query;

enum FusionStrategy: string
{
    /** Rank-based, needs no score normalisation: the portable default, computed in core when the engine cannot. */
    case ReciprocalRank = 'reciprocal_rank';
    /** Score-based and therefore not portable: compiled by engines with native hybrid fusion, never computed in core. */
    case Weighted = 'weighted';
}
