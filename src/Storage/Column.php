<?php

declare(strict_types=1);

namespace Orion\Storage;

/**
 * One column of an Orion table. Immutable; the fluent methods return copies.
 */
final readonly class Column
{
    private function __construct(
        public string $name,
        public ColumnType $type,
        public ?int $length,
        public bool $nullable,
        public bool $primary,
    ) {
        Identifier::assert($name);
    }

    public static function string(string $name, int $length): self
    {
        return new self($name, ColumnType::String, $length, false, false);
    }

    public static function text(string $name): self
    {
        return new self($name, ColumnType::Text, null, false, false);
    }

    public static function int(string $name): self
    {
        return new self($name, ColumnType::Int, null, false, false);
    }

    public static function datetime(string $name): self
    {
        return new self($name, ColumnType::DateTime, null, false, false);
    }

    public static function json(string $name): self
    {
        return new self($name, ColumnType::Json, null, false, false);
    }

    public function primary(): self
    {
        return new self($this->name, $this->type, $this->length, $this->nullable, true);
    }

    public function nullable(): self
    {
        return new self($this->name, $this->type, $this->length, true, $this->primary);
    }
}
