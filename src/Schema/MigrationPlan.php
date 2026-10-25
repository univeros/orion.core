<?php

declare(strict_types=1);

namespace Orion\Schema;

use Orion\IndexName;

use function array_filter;
use function array_keys;
use function array_push;
use function array_unique;
use function array_values;
use function strcmp;
use function usort;

/**
 * The classified diff of a desired schema against the live one (v1 §8.1), a pure function of its inputs.
 *
 * Classification is total and fails closed: a new field is additive, and every other difference (removal, type,
 * analysis, flags, vector shape, a live field of unknown type, any attribute added later) is breaking. Query-time
 * attributes (`boost`) are not index state, no engine reports them, and they are left out of the diff.
 */
final readonly class MigrationPlan
{
    /** Read when a query is compiled, never stored in the index. */
    private const array QUERY_TIME_ATTRIBUTES = ['boost'];

    /**
     * @param list<Change> $changes ordered by path
     */
    private function __construct(public IndexName $index, public array $changes)
    {
    }

    /**
     * A missing live index plans every desired field as added.
     */
    public static function between(IndexSchema $desired, ?LiveSchema $live): self
    {
        $changes = self::diff($live === null ? [] : $live->fields, $desired->fields, '');
        usort($changes, static fn(Change $a, Change $b): int => strcmp($a->path, $b->path));

        return new self($desired->index, $changes);
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * @return list<Change>
     */
    public function additive(): array
    {
        return $this->classified(Classification::Additive);
    }

    /**
     * @return list<Change>
     */
    public function breaking(): array
    {
        return $this->classified(Classification::Breaking);
    }

    public function requiresReindex(): bool
    {
        return $this->breaking() !== [];
    }

    /**
     * @param list<Field> $live
     * @param list<Field> $desired
     * @return list<Change>
     */
    private static function diff(array $live, array $desired, string $prefix): array
    {
        $before = Field::byName($live);
        $after = Field::byName($desired);
        $changes = [];
        foreach ($after as $name => $field) {
            if (!isset($before[$name])) {
                $changes[] = Change::added($prefix . $name, $field);
            } else {
                array_push($changes, ...self::compare($before[$name], $field, $prefix . $name));
            }
        }
        foreach ($before as $name => $field) {
            if (!isset($after[$name])) {
                $changes[] = Change::removed($prefix . $name, $field);
            }
        }

        return $changes;
    }

    /**
     * @return list<Change>
     */
    private static function compare(Field $live, Field $desired, string $path): array
    {
        $differing = self::differingAttributes($live, $desired);
        if ($differing === []) {
            return [];
        }
        if ($differing === ['fields']) {
            return self::diff($live->fields, $desired->fields, $path . '.');
        }

        return [Change::changed($path, $live, $desired)];
    }

    /**
     * Sub-fields differ when any of them does; recursion then finds which, ignoring order and query-time attributes.
     *
     * @return list<string>
     */
    private static function differingAttributes(Field $live, Field $desired): array
    {
        $before = self::indexState($live);
        $after = self::indexState($desired);
        $keys = array_unique([...array_keys($before), ...array_keys($after)]);
        $differing = array_filter($keys, static fn(string $key): bool => ($before[$key] ?? null) !== ($after[$key] ?? null));
        if (Field::mapToArray($live->fields) !== Field::mapToArray($desired->fields)) {
            $differing[] = 'fields';
        }

        return array_values($differing);
    }

    /**
     * @return array<string, mixed>
     */
    private static function indexState(Field $field): array
    {
        $attributes = $field->toArray();
        unset($attributes['fields']);
        foreach (self::QUERY_TIME_ATTRIBUTES as $attribute) {
            unset($attributes[$attribute]);
        }

        return $attributes;
    }

    /**
     * @return list<Change>
     */
    private function classified(Classification $classification): array
    {
        return array_values(array_filter($this->changes, static fn(Change $change): bool => $change->classification === $classification));
    }
}
