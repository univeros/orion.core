<?php

declare(strict_types=1);

namespace Orion\Schema;

/**
 * One classified difference: a field, addressed by dotted path (sub-fields of objects included), or a schema-level
 * setting the live index cannot report (the org key, an engine's native settings), addressed by a `_`-prefixed path
 * no field can have. Field changes carry fields; setting changes carry the declared values.
 */
final readonly class Change
{
    /**
     * @param Field|array<string, mixed>|string|null $before
     * @param Field|array<string, mixed>|string|null $after
     */
    private function __construct(
        public string $path,
        public ChangeKind $kind,
        public Classification $classification,
        public Field|array|string|null $before,
        public Field|array|string|null $after,
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

    /**
     * A schema-level setting differs from its registration. No engine can apply one in place and none reports it,
     * so every such difference is breaking, an addition included.
     *
     * @param array<string, mixed>|string|null $before
     * @param array<string, mixed>|string|null $after
     */
    public static function setting(string $path, array|string|null $before, array|string|null $after): self
    {
        $kind = match (true) {
            $before === null => ChangeKind::Added,
            $after === null => ChangeKind::Removed,
            default => ChangeKind::Changed,
        };

        return new self($path, $kind, Classification::Breaking, $before, $after);
    }

    /**
     * @return array{path: string, kind: string, classification: string, before: mixed, after: mixed}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'kind' => $this->kind->value,
            'classification' => $this->classification->value,
            'before' => $this->before instanceof Field ? $this->before->toArray() : $this->before,
            'after' => $this->after instanceof Field ? $this->after->toArray() : $this->after,
        ];
    }
}
