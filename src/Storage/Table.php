<?php

declare(strict_types=1);

namespace Orion\Storage;

use InvalidArgumentException;

use function array_filter;
use function array_values;
use function implode;
use function sprintf;

/**
 * A table as data, for installers and schema diffs. Immutable.
 */
final readonly class Table
{
    /**
     * @param list<Column> $columns
     * @param list<Index> $indexes
     */
    private function __construct(public string $name, public array $columns, public array $indexes)
    {
        Identifier::assert($name);
    }

    /**
     * @param list<Column> $columns
     */
    public static function named(string $name, array $columns): self
    {
        return new self($name, $columns, []);
    }

    /**
     * @param list<string> $columns
     */
    public function unique(array $columns): self
    {
        return new self($this->name, $this->columns, [...$this->indexes, new Index($columns, true, $this->indexName($columns, 'unique'))]);
    }

    /**
     * @param list<string> $columns
     */
    public function index(array $columns): self
    {
        return new self($this->name, $this->columns, [...$this->indexes, new Index($columns, false, $this->indexName($columns, 'index'))]);
    }

    public function column(string $name): Column
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        throw new InvalidArgumentException(sprintf('%s has no column "%s".', $this->name, $name));
    }

    /**
     * @return list<Column>
     */
    public function primaryKey(): array
    {
        return array_values(array_filter($this->columns, static fn(Column $column): bool => $column->primary));
    }

    /**
     * @param list<string> $columns
     */
    private function indexName(array $columns, string $suffix): string
    {
        return sprintf('%s_%s_%s', $this->name, implode('_', $columns), $suffix);
    }
}
