<?php

declare(strict_types=1);

namespace Orion\Schema;

enum Classification: string
{
    /** Applied in place, no reindex. */
    case Additive = 'additive';
    /** Never applied to a live index; requires a reindex. */
    case Breaking = 'breaking';
}
