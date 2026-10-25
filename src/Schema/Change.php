<?php

declare(strict_types=1);

namespace Orion\Schema;

/**
 * One classified difference, addressed by dotted path for sub-fields of objects.
 */
final readonly class Change
{
    private function __construct(
        public string $path,
        public ChangeKind $kind,
        public Classification $classification,
        public ?Field $before,
        public ?Field $after,
    ) {
    }

    public static function added(string $path, Field $after): self
    {
        return new self($path, ChangeKind::Added, Classification::Additive, null, $after);
    }

    public static function removed(string $path, Field $before): self
    {
        return new self($path, ChangeKind::Removed, Classification::Breaking, $before, null);
    }

    public static function changed(string $path, Field $before, Field $after): self
    {
        return new self($path, ChangeKind::Changed, Classification::Breaking, $before, $after);
    }
}
