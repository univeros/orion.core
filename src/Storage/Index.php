<?php

declare(strict_types=1);

namespace Orion\Storage;

final readonly class Index
{
    /**
     * @param list<string> $columns
     */
    public function __construct(public array $columns, public bool $unique, public string $name)
    {
        Identifier::assert($name);
        foreach ($columns as $column) {
            Identifier::assert($column);
        }
    }
}
