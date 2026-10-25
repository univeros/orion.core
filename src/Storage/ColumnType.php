<?php

declare(strict_types=1);

namespace Orion\Storage;

enum ColumnType: string
{
    case String = 'string';
    case Text = 'text';
    /** 64-bit. */
    case Int = 'int';
    case DateTime = 'datetime';
    case Json = 'json';
}
