<?php

declare(strict_types=1);

namespace Orion\Schema;

use Orion\IndexName;

/**
 * What an engine reports for a physical index, in neutral fields; a native type with no neutral equivalent is
 * reported as {@see Field::unknown()} so the planner fails closed on it.
 */
final readonly class LiveSchema
{
    /**
     * @param list<Field> $fields
     */
    public function __construct(public IndexName $index, public array $fields)
    {
        Field::byName($fields);
    }
}
