<?php

declare(strict_types=1);

namespace Orion\Query;

enum Direction: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
