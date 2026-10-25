<?php

declare(strict_types=1);

namespace Orion\Schema;

/**
 * The neutral field types (v1 §6). `Object` and `Nested` only on engines that report support.
 */
enum FieldType: string
{
    case Text = 'text';
    case Keyword = 'keyword';
    case Integer = 'integer';
    case Float = 'float';
    case Boolean = 'boolean';
    case Date = 'date';
    case Geo = 'geo';
    case Vector = 'vector';
    case Object = 'object';
    case Nested = 'nested';
    /** A live field whose native type has no neutral equivalent. Never declarable; any change to it is breaking. */
    case Unknown = 'unknown';
}
